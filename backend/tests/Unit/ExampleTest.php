<?php

namespace Tests\Unit;

use App\Models\Photo;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function test_legacy_constants_match_old_cms_values(): void
    {
        $this->assertSame(5, User::TYPE_ADMIN);
        $this->assertSame(1, User::TYPE_BLOCKED);
        $this->assertSame('Հյուսիս', Photo::DIRECTIONS[1]);
    }
}
