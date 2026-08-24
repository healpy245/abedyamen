<?php

declare(strict_types=1);

namespace Tests\Unit\AppDevelopment;

use App\Support\AppDevelopment\FileSize;
use PHPUnit\Framework\TestCase;

class FileSizeTest extends TestCase
{
    public function test_formats_bytes(): void
    {
        $this->assertSame('512 B', FileSize::format(512));
        $this->assertSame('1.0 KB', FileSize::format(1024));
        $this->assertSame('81.4 MB', FileSize::format(85354086));
    }
}
