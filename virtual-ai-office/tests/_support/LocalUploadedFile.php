<?php

namespace Tests\Support;

use CodeIgniter\HTTP\Files\UploadedFile;

/** Test double: behaves like an HTTP upload but moves a local file (move_uploaded_file() would refuse it). */
final class LocalUploadedFile extends UploadedFile
{
    public function move(string $targetPath, ?string $name = null, bool $overwrite = false)
    {
        if (! is_dir($targetPath)) {
            mkdir($targetPath, 0777, true);
        }
        $name ??= $this->getName();
        copy($this->getTempName(), rtrim($targetPath, '/') . '/' . $name);
        $this->name = $name;

        return true;
    }

    public function isValid(): bool
    {
        return is_file($this->getTempName());
    }
}
