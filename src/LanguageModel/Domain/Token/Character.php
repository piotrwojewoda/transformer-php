<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Token;

// A "codepoint" is a number that stands for one Unicode character.
// For example, the letter 'A' is codepoint 65, the emoji '🎉' is
// codepoint 127881. The range 0 to 0x10FFFF covers all valid
// Unicode codepoints.
final readonly class Character
{
    public function __construct(public int $codepoint)
    {
        if ($codepoint < 0) {
            throw new \InvalidArgumentException('Character codepoint must be non-negative, got: '.$codepoint);
        }
        // 0x10FFFF is the maximum Unicode codepoint. Anything above
        // is not a real character.
        if ($codepoint > 0x10FFFF) {
            throw new \InvalidArgumentException('Character codepoint out of Unicode range: '.$codepoint);
        }
    }

    /**
     * Build a Character from a string. The string should be exactly
     * one Unicode character. (Multi-character strings would be a
     * bug, so we refuse them.)
     */
    public static function fromChar(string $char): self
    {
        if (\strlen($char) === 0) {
            throw new \InvalidArgumentException('Character cannot be empty.');
        }
        $codepoint = mb_ord($char, 'UTF-8');
        if ($codepoint === false) {
            throw new \InvalidArgumentException('Invalid UTF-8 character.');
        }

        return new self($codepoint);
    }

    /**
     * Turn the codepoint back into a real string. If the codepoint
     // is somehow invalid (shouldn't happen with our checks), we
     // return a question mark instead of breaking the program.
     */
    public function toChar(): string
    {
        $char = mb_chr($this->codepoint, 'UTF-8');

        return $char === false ? '?' : $char;
    }

    public function equals(self $other): bool
    {
        return $this->codepoint === $other->codepoint;
    }

    public function __toString(): string
    {
        return $this->toChar();
    }
}
