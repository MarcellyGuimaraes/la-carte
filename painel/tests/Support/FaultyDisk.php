<?php

namespace Tests\Support;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Disco falso que falha sob comando, para testar resiliência.
 *
 * Embrulha o Storage::fake: tudo funciona igual, exceto os caminhos que o
 * teste mandar falhar. Falha "de verdade" como o Laravel reporta com
 * throw=false: put/delete devolvem false.
 */
class FaultyDisk extends FilesystemAdapter
{
    /** @var (Closure(string): bool)|null */
    private ?Closure $failPut = null;

    /** @var (Closure(string): bool)|null */
    private ?Closure $truncatePut = null;

    private bool $failDelete = false;

    /** @var (Closure(string): void)|null */
    private ?Closure $beforePut = null;

    /** Troca o disco [$name] por um fake que falha sob comando. */
    public static function replace(string $name): self
    {
        $fake = Storage::fake($name);
        $disk = new self($fake->getDriver(), $fake->getAdapter(), $fake->getConfig());
        Storage::set($name, $disk);

        return $disk;
    }

    /** @param  Closure(string): bool  $when */
    public function failPutWhen(Closure $when): self
    {
        $this->failPut = $when;

        return $this;
    }

    /** Grava só metade do conteúdo e diz que deu certo: upload corrompido. */
    public function truncatePutWhen(Closure $when): self
    {
        $this->truncatePut = $when;

        return $this;
    }

    public function failDeletes(bool $fail = true): self
    {
        $this->failDelete = $fail;

        return $this;
    }

    /** Roda algo no meio da gravação (ex.: outra conexão tentando o lock). */
    public function beforePut(Closure $callback): self
    {
        $this->beforePut = $callback;

        return $this;
    }

    public function heal(): self
    {
        $this->failPut = null;
        $this->truncatePut = null;
        $this->failDelete = false;
        $this->beforePut = null;

        return $this;
    }

    public function put($path, $contents, $options = [])
    {
        if ($this->beforePut) {
            ($this->beforePut)($path);
        }

        if ($this->failPut && ($this->failPut)($path)) {
            return false;
        }

        if ($this->truncatePut && ($this->truncatePut)($path) && is_string($contents)) {
            return parent::put($path, substr($contents, 0, intdiv(strlen($contents), 2)), $options);
        }

        return parent::put($path, $contents, $options);
    }

    public function delete($paths)
    {
        return $this->failDelete ? false : parent::delete($paths);
    }
}
