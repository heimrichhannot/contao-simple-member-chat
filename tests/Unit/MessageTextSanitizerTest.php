<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Exception\ChatException;
use HeimrichHannot\SimpleMemberChatBundle\Service\MessageTextSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageTextSanitizerTest extends TestCase
{
    public function testNormalizesPlainTextWithoutStrippingMarkup(): void
    {
        $sanitizer = new MessageTextSanitizer(new ChatOptions());
        self::assertSame("<b>Hello</b>\nworld\n\t!", $sanitizer->sanitize(" \0<b>Hello</b>\r\nworld\r\t!\x7F "));
        self::assertSame('Hello', $sanitizer->sanitize("\u{00A0}Hello\u{00A0}"));
    }

    public function testCountsUnicodeCharacters(): void
    {
        $sanitizer = new MessageTextSanitizer(new ChatOptions(maxLength: 2));
        self::assertSame('😀ä', $sanitizer->sanitize('😀ä'));
    }

    #[DataProvider('invalidBodies')]
    public function testRejectsInvalidBodies(string $body): void
    {
        $this->expectException(ChatException::class);
        new MessageTextSanitizer(new ChatOptions(maxLength: 2))->sanitize($body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [" \t\r\n "];
        yield 'control characters' => ["\x00\x01\x7f"];
        yield 'too long' => ['😀äz'];
        yield 'invalid UTF-8' => ["\xff"];
    }
}
