<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Flarum\Extension;

use Flarum\Database\Migrator;
use Flarum\Extend\Compat;
use Flarum\Extend\LifecycleInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\MountManager;
use League\Flysystem\Plugin\ListFiles;

/**
 * @property string $name
 * @property string $description
 * @property string $type
 * @property array  $keywords
 * @property string $homepage
 * @property string $time
 * @property string $license
 * @property array  $authors
 * @property array  $support
 * @property array  $require
 * @property array  $requireDev
 * @property array  $autoload
 * @property array  $autoloadDev
 * @property array  $conflict
 * @property array  $replace
 * @property array  $provide
 * @property array  $suggest
 * @property array  $extra
 */
class Extension implements Arrayable
{
    const LOGO_MIMETYPES = [
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
    ];

    protected static function nameToId($name)
    {
        list($vendor, $package) = explode('/', $name);
        $package = str_replace(['flarum-ext-', 'flarum-'], '', $package);

        return "$vendor-$package";
    }

    /**
     * Unique Id of the extension.
     *
     * @info    Identical to the directory in the extensions directory.
     * @example flarum_suspend
     *
     * @var string
     */
    protected $id;

    /**
     * The directory of this extension.
     *
     * @var string
     */
    protected $path;

    /**
     * Composer json of the package.
     *
     * @var array
     */
    protected $composerJson;

    /**
     * The IDs of all Flarum extensions that this extension depends on.
     *
     * @var string[]
     */
    protected $extensionDependencyIds;

    /**
     * Whether the extension is installed.
     *
     * @var bool
     */
    protected $installed = true;

    /**
     * The installed version of the extension.
     *
     * @var string
     */
    protected $version;

    /**
     * @param       $path
     * @param array $composerJson
     */
    public function __construct($path, $composerJson)
    {
        $this->path = $path;
        $this->composerJson = $composerJson;
        $this->assignId();
    }

    /**
     * Assigns the id for the extension used globally.
     */
    protected function assignId()
    {
        $this->id = static::nameToId($this->name);
    }

    public function extend(Container $app)
    {
        foreach ($this->getExtenders() as $extender) {
            // If an extension has not yet switched to the new extend.php
            // format, it might return a function (or more of them). We wrap
            // these in a Compat extender to enjoy an unique interface.
            if ($extender instanceof \Closure || is_string($extender)) {
                $extender = new Compat($extender);
            }

            $extender->extend($app, $this);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function __get($name)
    {
        return $this->composerJsonAttribute(Str::snake($name, '-'));
    }

    /**
     * {@inheritdoc}
     */
    public function __isset($name)
    {
        return isset($this->{$name}) || $this->composerJsonAttribute(Str::snake($name, '-'));
    }

    /**
     * Dot notation getter for composer.json attributes.
     *
     * @see https://laravel.com/docs/5.1/helpers#arrays
     *
     * @param $name
     * @return mixed
     */
    public function composerJsonAttribute($name)
    {
        return Arr::get($this->composerJson, $name);
    }

    /**
     * @param bool $installed
     * @return Extension
     */
    public function setInstalled($installed)
    {
        $this->installed = $installed;

        return $this;
    }

    /**
     * @return bool
     */
    public function isInstalled()
    {
        return $this->installed;
    }

    /**
     * @param string $version
     * @return Extension
     */
    public function setVersion($version)
    {
        $this->version = $version;

        return $this;
    }

    /**
     * Get the list of flarum extensions that this extension depends on.
     *
     * @param array $extensionSet: An associative array where keys are the composer package names
     *                             of installed extensions. Used to figure out which dependencies
     *                             are flarum extensions.
     */
    public function calculateDependencies($extensionSet)
    {
        $this->extensionDependencyIds = (new Collection(Arr::get($this->composerJson, 'require', [])))
            ->keys()
            ->filter(function ($key) use ($extensionSet) {
                return array_key_exists($key, $extensionSet);
            })->map(function ($key) {
                return static::nameToId($key);
            })->toArray();
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * Loads the icon information from the composer.json.
     *
     * @return array|null
     */
    public function getIcon()
    {
        $icon = $this->composerJsonAttribute('extra.flarum-extension.icon');
        $file = Arr::get($icon, 'image');

        if (is_null($icon) || is_null($file)) {
            return $icon;
        }

        $file = $this->path.'/'.$file;

        if (file_exists($file)) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            if (! array_key_exists($extension, self::LOGO_MIMETYPES)) {
                throw new \RuntimeException('Invalid image type');
            }

            $mimetype = self::LOGO_MIMETYPES[$extension];
            $data = base64_encode(file_get_contents($file));

            $icon['backgroundImage'] = "url('data:$mimetype;base64,$data')";
        }

        return $icon;
    }

    public function enable(Container $container)
    {
        foreach ($this->getLifecycleExtenders() as $extender) {
            $extender->onEnable($container, $this);
        }
    }

    public function disable(Container $container)
    {
        foreach ($this->getLifecycleExtenders() as $extender) {
            $extender->onDisable($container, $this);
        }
    }

    /**
     * The raw path of the directory under extensions.
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * The IDs of all Flarum extensions that this extension depends on.
     *
     * @return array
     */
    public function getExtensionDependencyIds()
    {
        return $this->extensionDependencyIds;
    }

    private function getExtenders(): array
    {
        $extenderFile = $this->getExtenderFile();

        if (! $extenderFile) {
            return [];
        }

        $extenders = require $extenderFile;

        if (! is_array($extenders)) {
            $extenders = [$extenders];
        }

        return Arr::flatten($extenders);
    }

    /**
     * @return LifecycleInterface[]
     */
    private function getLifecycleExtenders(): array
    {
        return array_filter(
            $this->getExtenders(),
            function ($extender) {
                return $extender instanceof LifecycleInterface;
            }
        );
    }

    private function getExtenderFile(): ?string
    {
        $filename = "{$this->path}/extend.php";

        if (file_exists($filename)) {
            return $filename;
        }

        return null;
    }

    /**
     * Compile a list of links for this extension.
     */
    public function getLinks()
    {
        $links = [];

        if (($sourceUrl = $this->composerJsonAttribute('source.url')) || ($sourceUrl = $this->composerJsonAttribute('support.source'))) {
            $links['source'] = $sourceUrl;
        }

        if (($discussUrl = $this->composerJsonAttribute('support.forum'))) {
            $links['discuss'] = $discussUrl;
        }

        if (($documentationUrl = $this->composerJsonAttribute('support.docs'))) {
            $links['documentation'] = $documentationUrl;
        }

        if (($websiteUrl = $this->composerJsonAttribute('homepage'))) {
            $links['website'] = $websiteUrl;
        }

        if (($supportEmail = $this->composerJsonAttribute('support.email'))) {
            $links['support'] = "mailto:$supportEmail";
        }

        if (($funding = $this->composerJsonAttribute('funding')) && count($funding)) {
            $links['donate'] = $funding[0]['url'];
        }

        $links['authors'] = [];

        foreach ((array) $this->composerJsonAttribute('authors') as $author) {
            $links['authors'][] = [
                'name' => Arr::get($author, 'name'),
                'link' => Arr::get($author, 'homepage') ?? (Arr::get($author, 'email') ? 'mailto:'.Arr::get($author, 'email') : ''),
            ];
        }

        return array_merge($links, $this->composerJsonAttribute('extra.flarum-extension.links') ?? []);
    }

    /**
     * Tests whether the extension has assets.
     *
     * @return bool
     */
    public function hasAssets()
    {
        return realpath($this->path.'/assets/') !== false;
    }

    public function copyAssetsTo(FilesystemInterface $target)
    {
        if (! $this->hasAssets()) {
            return;
        }

        $mount = new MountManager([
            'source' => $source = new Filesystem(new Local($this->getPath().'/assets')),
            'target' => $target,
        ]);

        $source->addPlugin(new ListFiles);
        $assetFiles = $source->listFiles('/', true);

        foreach ($assetFiles as $file) {
            $mount->copy("source://$file[path]", "target://extensions/$this->id/$file[path]");
        }
    }

    /**
     * Tests whether the extension has migrations.
     *
     * @return bool
     */
    public function hasMigrations()
    {
        return realpath($this->path.'/migrations/') !== false;
    }

    public function migrate(Migrator $migrator, $direction = 'up')
    {
        if (! $this->hasMigrations()) {
            return;
        }

        if ($direction == 'up') {
            return $migrator->run($this->getPath().'/migrations', $this);
        } else {
            return $migrator->reset($this->getPath().'/migrations', $this);
        }
    }

    /**
     * Generates an array result for the object.
     *
     * @return array
     */
    public function toArray()
    {
        return (array) array_merge([
            'id'                     => $this->getId(),
            'version'                => $this->getVersion(),
            'path'                   => $this->getPath(),
            'icon'                   => $this->getIcon(),
            'hasAssets'              => $this->hasAssets(),
            'hasMigrations'          => $this->hasMigrations(),
            'extensionDependencyIds' => $this->getExtensionDependencyIds(),
            'links'                  => $this->getLinks(),
        ], $this->composerJson);
    }
}
