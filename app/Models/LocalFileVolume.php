<?php

namespace App\Models;

use App\Events\FileStorageChanged;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LocalFileVolume extends BaseModel
{
    protected $casts = [
        // 'fs_path' => 'encrypted',
        // 'mount_path' => 'encrypted',
        'content' => 'encrypted',
        'is_directory' => 'boolean',
    ];

    use HasFactory;

    protected $guarded = [];

    public $appends = ['is_binary'];

    protected static function booted()
    {
        static::created(function (LocalFileVolume $fileVolume) {
            $fileVolume->load(['service']);
            dispatch(new \App\Jobs\ServerStorageSaveJob($fileVolume));
        });
    }

    protected function isBinary(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->content === '[binary file]';
            }
        );
    }

    public function service()
    {
        return $this->morphTo('resource');
    }

    public function loadStorageOnServer()
    {
        $this->load(['service']);
        $isService = data_get($this->resource, 'service');
        if ($isService) {
            $workdir = $this->resource->service->workdir();
            $server = $this->resource->service->server;
        } else {
            $workdir = $this->resource->workdir();
            $server = $this->resource->destination->server;
        }
        $commands = collect([]);
        $path = data_get_str($this, 'fs_path');
        if ($path->startsWith('.')) {
            $path = $path->after('.');
            $path = $workdir.$path;
        }
        $isFile = instant_remote_process(["test -f $path && echo OK || echo NOK"], $server);
        if ($isFile === 'OK') {
            $content = instant_remote_process(["cat $path"], $server, false);
            // Check if content contains binary data by looking for null bytes or non-printable characters
            if (str_contains($content, "\0") || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $content)) {
                $content = '[binary file]';
            }
            $this->content = $content;
            $this->is_directory = false;
            $this->save();
        }
    }

    public function deleteStorageOnServer()
    {
        $this->load(['service']);
        $isService = data_get($this->resource, 'service');
        if ($isService) {
            $workdir = $this->resource->service->workdir();
            $server = $this->resource->service->server;
        } else {
            $workdir = $this->resource->workdir();
            $server = $this->resource->destination->server;
        }
        $commands = collect([]);
        $path = data_get_str($this, 'fs_path');
        if ($path->startsWith('.')) {
            $path = $path->after('.');
            $path = $workdir.$path;
        }
        $isFile = instant_remote_process(["test -f $path && echo OK || echo NOK"], $server);
        $isDir = instant_remote_process(["test -d $path && echo OK || echo NOK"], $server);
        if ($path && $path != '/' && $path != '.' && $path != '..') {
            if ($isFile === 'OK') {
                $commands->push("rm -rf $path > /dev/null 2>&1 || true");
            } elseif ($isDir === 'OK') {
                $commands->push("rm -rf $path > /dev/null 2>&1 || true");
                $commands->push("rmdir $path > /dev/null 2>&1 || true");
            }
        }
        if ($commands->count() > 0) {
            return instant_remote_process($commands, $server);
        }
    }

    public function saveStorageOnServer()
    {
        $this->load(['service']);
        $isService = data_get($this->resource, 'service');
        if ($isService) {
            $workdir = $this->resource->service->workdir();
            $server = $this->resource->service->server;
        } else {
            $workdir = $this->resource->workdir();
            $server = $this->resource->destination->server;
        }
        $commands = collect([]);
        if ($this->is_directory) {
            $commands->push("mkdir -p $this->fs_path > /dev/null 2>&1 || true");
            $commands->push("cd $workdir");
        }
        if (str($this->fs_path)->startsWith('.') || str($this->fs_path)->startsWith('/') || str($this->fs_path)->startsWith('~')) {
            $parent_dir = str($this->fs_path)->beforeLast('/');
            if ($parent_dir != '') {
                $commands->push("mkdir -p $parent_dir > /dev/null 2>&1 || true");
            }
        }
        $path = data_get_str($this, 'fs_path');
        $content = data_get($this, 'content');
        if ($path->startsWith('.')) {
            $path = $path->after('.');
            $path = $workdir.$path;
        }
        $isFile = instant_remote_process(["test -f $path && echo OK || echo NOK"], $server);
        $isDir = instant_remote_process(["test -d $path && echo OK || echo NOK"], $server);
        if ($isFile === 'OK' && $this->is_directory) {
            $content = instant_remote_process(["cat $path"], $server, false);
            $this->is_directory = false;
            $this->content = $content;
            $this->save();
            FileStorageChanged::dispatch(data_get($server, 'team_id'));
            throw new \Exception('The following file is a file on the server, but you are trying to mark it as a directory. Please delete the file on the server or mark it as directory.');
        } elseif ($isDir === 'OK' && ! $this->is_directory) {
            if ($path === '/' || $path === '.' || $path === '..' || $path === '' || str($path)->isEmpty() || is_null($path)) {
                $this->is_directory = true;
                $this->save();
                throw new \Exception('The following file is a directory on the server, but you are trying to mark it as a file. <br><br>Please delete the directory on the server or mark it as directory.');
            }
            instant_remote_process([
                "rm -fr $path",
                "touch $path",
            ], $server, false);
            FileStorageChanged::dispatch(data_get($server, 'team_id'));
        }
        if ($isDir === 'NOK' && ! $this->is_directory) {
            $chmod = data_get($this, 'chmod');
            $chown = data_get($this, 'chown');
            if ($content) {
                $content = base64_encode($content);
                $commands->push("echo '$content' | base64 -d | tee $path > /dev/null");
            } else {
                $commands->push("touch $path");
            }
            $commands->push("chmod +x $path");
            if ($chown) {
                $commands->push("chown $chown $path");
            }
            if ($chmod) {
                $commands->push("chmod $chmod $path");
            }
        } elseif ($isDir === 'NOK' && $this->is_directory) {
            $commands->push("mkdir -p $path > /dev/null 2>&1 || true");
        }

        return instant_remote_process($commands, $server);
    }

    // Accessor for convenient access
    protected function plainMountPath(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->mount_path,
            set: fn ($value) => $this->mount_path = $value
        );
    }

    // Scope for searching
    public function scopeWherePlainMountPath($query, $path)
    {
        return $query->get()->where('plain_mount_path', $path);
    }
}
