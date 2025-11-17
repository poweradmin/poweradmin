<?php

declare(strict_types=1);

namespace VarRepresentation;

use RuntimeException;
use VarRepresentation\Node\Array_;
use VarRepresentation\Node\ArrayEntry;
use VarRepresentation\Node\Group;
use VarRepresentation\Node\Object_;

/**
 * Encodes var_export output into var_representation() output
 */
class Encoder
{
    /** @var list<string|array{0:int,1:string,2:int}> the raw tokens from token_get_all */
    protected $tokens;
    /** @var int the last valid index */
    protected $endIndex;
    /** @var string the original raw var_export output */
    protected $raw;
    /** @var int the current offset */
    protected $i = 1;
    /** @var bool whether the flags for the most recent call are VAR_REPRESENTATION_UNESCAPED */
    protected $unescaped = false;

    protected function __construct(string $raw)
    {
        $this->tokens = self::getTokensWithoutWhitespace($raw);
        $this->endIndex = \count($this->tokens);
        $this->raw = $raw;
        unset($this->tokens[0]);
    }

    /**
     * Get tokens without T_WHITESPACE tokens
     * @return list<string|array{0:int,1:string,2:int}>
     * @api
     */
    public static function getTokensWithoutWhitespace(string $raw): array
    {
        $tokens = \token_get_all('<?php ' . $raw);
        foreach ($tokens as $i => $token) {
            if (\is_array($token) && $token[0] === \T_WHITESPACE) {
                unset($tokens[$i]);
            }
        }
        return \array_values($tokens);
    }

    /**
     * Generate a readable var_representation from the original var_export output
     * @param mixed $value
     * @param int $flags bitmask of flags (VAR_REPRESENTATION_SINGLE_LINE)
     */
    public static function toVarRepresentation($value, int $flags = 0): string
    {
        $raw_string = \var_export($value, true);
        if (!\function_exists('token_get_all')) {
            return $raw_string;
        }

        return (new self($raw_string))->encode($flags);
    }

    /**
     * Encode the entire sequence of tokens
     */
    protected function encode(int $flags): string
    {
        $this->unescaped = ($flags & \VAR_REPRESENTATION_UNESCAPED) !== 0;
        $result = $this->encodeValue();
        if ($this->i !== \count($this->tokens) + 1) {
            throw new RuntimeException("Failed to read token #$this->i of $this->raw: " . \var_export($this->tokens[$this->i] ?? null, true));
        }
        if ($flags & \VAR_REPRESENTATION_SINGLE_LINE) {
            return $result->__toString();
        }
        return $result->toIndentedString(0);
    }

    /**
     * Read the current token and advance
     * @return string|array{0:int,1:string,2:int}
     */
    private function getToken()
    {
        $token = $this->tokens[$this->i++];
        if ($token === null) {
            throw new RuntimeException("Unexpected end of tokens in $this->raw");
        }
        return $token;
    }

    /**
     * Read the current token without advancing
     * @return string|array{0:int,1:string,2:int}
     */
    private function peekToken()
    {
        $token = $this->tokens[$this->i];
        if ($token === null) {
            throw new RuntimeException("Unexpected end of tokens in $this->raw");
        }
        return $token;
    }

    /**
     * Convert a expression representation to the readable representation
     */
    protected function encodeValue(): Node
    {
        $values = [];
        while (true) {
            $token = $this->peekToken();
            if (\is_string($token)) {
                if ($token === ',') {
                    if (!$values) {
                        throw new RuntimeException("Unexpected token '$token', expected expression in $this->raw at token #$this->i");
                    }
                    break;
                }
                if ($token === ')') {
                    throw new RuntimeException("Unexpected token '$token', expected expression in $this->raw at token #$this->i");
                }
                $this->i++;
                if ($token === '(') {
                    return $this->encodeObject(\implode('', $values) . '(');
                }
                // TODO: Handle `*` in *RECURSION*, `-`, etc
                $values[] = $token;
            } else {
                $this->i++;
                // TODO: Handle PHP_INT_MIN as a multi-part expression, strings, etc
                switch ($token[0]) {
                    case \T_DOUBLE_ARROW:
                        $this->i--;
                        break 2;
                    case \T_CONSTANT_ENCAPSED_STRING:
                        $values[] = $this->encodeString($token[1]);
                        break;
                    case \T_ARRAY:
                        $next = $this->getToken();
                        if ($next !== '(') {
                            throw $this->createUnexpectedTokenException("'('", $next);
                        }
                        $values[] = $this->encodeArray();
                        break;
                    case \T_OBJECT_CAST:
                        $values[] = $token[1];
                        $values[] = ' ';
                        break;
                    case \T_STRING:
                        switch ($token[1]) {
                            case 'NULL';
                                $values[] = 'null';
                            break 2;
                            /*
                        case 'stdClass':
                            // $this->encodeLegacyStdClass();
                            $next = $this->getToken();
                            if ($next !== T_DOUBLE_COLON) {
                                throw $this->createUnexpectedTokenException("'::'", $next);
                            }
                             */
                        }
                    default:
                        $values[] = $token[1];
                }
            }
            if ($this->i >= $this->endIndex) {
                break;
            }
        }
        return Group::fromParts($values);
    }

    /**
     * Unescape a string literal generated by var_export
     */
    protected static function unescapeStringRepresentation(string $value): string
    {
        if ($value === '"\0"') {
            return "\0";
        }
        return \preg_replace('/\\\\([\'\\\\])/', '\1', (string)\substr($value, 1, -1));
    }

    private const CHAR_LOOKUP = [
        "\n" => '\n',
        "\t" => '\t',
        "\r" => '\r',
        '"' => '\"',
        '\\' => '\\\\',
        '$' => '\$',
    ];

    /**
     * Outputs an encoded string representation
     */
    protected function encodeString(string $prefix): Group
    {
        $unescaped_str = self::unescapeStringRepresentation($prefix);
        while ($this->i < $this->endIndex && $this->peekToken() === '.') {
            $this->i++;
            $token = $this->getToken();
            if (!\is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
                throw $this->createUnexpectedTokenException('T_CONSTANT_ENCAPSED_STRING', $token);
            }
            $unescaped_str .= self::unescapeStringRepresentation($token[1]);
        }
        if (!\preg_match('/[\\x00-\\x1f\\x7f]/', $unescaped_str)) {
            // This does not have '"\0"', so it is already a single quoted string
            return new Group([$prefix]);
        }
        if ($this->unescaped) {
            $repr = self::encodeRawStringUnescapedSingleQuoted($unescaped_str);
        } else {
            $repr = self::encodeRawStringDoubleQuoted($unescaped_str);
        }
        return new Group([$repr]);
    }

    /**
     * Returns the representation of $raw in a single or double quoted string,
     * the way var_representation() would
     * @api
     */
    public static function encodeRawString(string $raw): string
    {
        if (!\preg_match('/[\\x00-\\x1f\\x7f]/', $raw)) {
            // This does not have '"\0"', so var_export will return a single quoted string
            return \var_export($raw, true);
        }
        return self::encodeRawStringDoubleQuoted($raw);
    }

    /**
     * Returns the representation of $raw in a double quoted string
     * @api
     */
    public static function encodeRawStringDoubleQuoted(string $raw): string
    {
        return '"' . \preg_replace_callback(
            '/[\\x00-\\x1f\\x7f\\\\"$]/',
            /** @param array{0:string} $match */
            static function (array $match): string {
                $char = $match[0];
                return self::CHAR_LOOKUP[$char] ?? \sprintf('\x%02x', \ord($char));
            },
            $raw
        ) . '"';
    }

    /**
     * Returns the representation of $raw in an unescaped single quoted string
     * (only escaping \\ and \', not escaping other control characters)
     * @api
     */
    public static function encodeRawStringUnescapedSingleQuoted(string $raw): string
    {
        return "'" . \preg_replace('/[\'\\\\]/', '\\\0', $raw) . "'";
    }

    /**
     * Encode an array
     */
    protected function encodeArray(): Array_
    {
        $entries = [];
        while (true) {
            $token = $this->peekToken();
            if ($token === ')') {
                $this->i++;
                break;
            }
            $key = $this->encodeValue();
            $token = $this->getToken();
            if (!\is_array($token) || $token[0] !== \T_DOUBLE_ARROW) {
                throw $this->createUnexpectedTokenException("'=>'", $token);
            }
            $value = $this->encodeValue();
            $entries[] = new ArrayEntry($key, $value);

            $token = $this->getToken();
            if ($token !== ',') {
                throw $this->createUnexpectedTokenException("','", $token);
            }
        }
        return new Array_($entries);
    }

    /**
     * Throw an exception for an unexpected token
     * @param string|array{0:int,1:string,2:int} $token
     */
    private function createUnexpectedTokenException(string $expected, $token): RuntimeException
    {
        return new RuntimeException("Expected $expected but got " . \var_export($token, true) . ' in ' . $this->raw);
    }


    /**
     * Encode an object from a set_state call
     */
    protected function encodeObject(string $prefix): Object_
    {
        $token = $this->getToken();
        if (!\is_array($token) || $token[0] !== \T_ARRAY) {
            throw $this->createUnexpectedTokenException('T_ARRAY', $token);
        }
        $token = $this->getToken();
        if ($token !== '(') {
            throw $this->createUnexpectedTokenException("'('", $token);
        }
        $array = $this->encodeArray();
        $token = $this->getToken();
        if ($token !== ')') {
            throw $this->createUnexpectedTokenException("')'", $token);
        }
        return new Object_($prefix, $array, ')');
    }
}
