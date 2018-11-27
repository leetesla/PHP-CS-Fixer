<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Tokenizer;

use PhpCsFixer\Preg;
use PhpCsFixer\Utils;

/**
 * Collection of code tokens.
 *
 * Its role is to provide the ability to manage collection and navigate through it.
 *
 * As a token prototype you should understand a single element generated by token_get_all.
 *
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * @method Token current()
 * @method Token offsetGet($index)
 */
class Tokens extends \SplFixedArray
{
    const BLOCK_TYPE_PARENTHESIS_BRACE = 1;
    const BLOCK_TYPE_CURLY_BRACE = 2;
    const BLOCK_TYPE_INDEX_SQUARE_BRACE = 3;
    const BLOCK_TYPE_ARRAY_SQUARE_BRACE = 4;
    const BLOCK_TYPE_DYNAMIC_PROP_BRACE = 5;
    const BLOCK_TYPE_DYNAMIC_VAR_BRACE = 6;
    const BLOCK_TYPE_ARRAY_INDEX_CURLY_BRACE = 7;
    const BLOCK_TYPE_GROUP_IMPORT_BRACE = 8;
    const BLOCK_TYPE_DESTRUCTURING_SQUARE_BRACE = 9;
    const BLOCK_TYPE_BRACE_CLASS_INSTANTIATION = 10;

    /**
     * Static class cache.
     *
     * @var array
     */
    private static $cache = [];

    /**
     * Cache of block edges. Any change in collection will invalidate it.
     *
     * @var array<int, int>
     */
    private $blockEndCache = [];

    /**
     * crc32 hash of code string.
     *
     * @var string
     */
    private $codeHash;

    /**
     * Flag is collection was changed.
     *
     * It doesn't know about change of collection's items. To check it run `isChanged` method.
     *
     * @var bool
     */
    private $changed = false;

    /**
     * Set of found token kinds.
     *
     * When the token kind is present in this set it means that given token kind
     * was ever seen inside the collection (but may not be part of it any longer).
     * The key is token kind and the value is always true.
     *
     * @var array<int|string, int>
     */
    private $foundTokenKinds = [];

    /**
     * Clone tokens collection.
     */
    public function __clone()
    {
        foreach ($this as $key => $val) {
            $this[$key] = clone $val;
        }
    }

    /**
     * Clear cache - one position or all of them.
     *
     * @param null|string $key position to clear, when null clear all
     */
    public static function clearCache($key = null)
    {
        if (null === $key) {
            self::$cache = [];

            return;
        }

        if (self::hasCache($key)) {
            unset(self::$cache[$key]);
        }
    }

    /**
     * Detect type of block.
     *
     * @param Token $token token
     *
     * @return null|array array with 'type' and 'isStart' keys or null if not found
     */
    public static function detectBlockType(Token $token)
    {
        foreach (self::getBlockEdgeDefinitions() as $type => $definition) {
            if ($token->equals($definition['start'])) {
                return ['type' => $type, 'isStart' => true];
            }

            if ($token->equals($definition['end'])) {
                return ['type' => $type, 'isStart' => false];
            }
        }
    }

    /**
     * Create token collection from array.
     *
     * @param Token[] $array       the array to import
     * @param bool    $saveIndexes save the numeric indexes used in the original array, default is yes
     *
     * @return Tokens
     */
    public static function fromArray($array, $saveIndexes = null)
    {
        $tokens = new self(\count($array));

        if (null === $saveIndexes || $saveIndexes) {
            foreach ($array as $key => $val) {
                $tokens[$key] = $val;
            }
        } else {
            $index = 0;

            foreach ($array as $val) {
                $tokens[$index++] = $val;
            }
        }

        $tokens->generateCode(); // regenerate code to calculate code hash

        return $tokens;
    }

    /**
     * Create token collection directly from code.
     *
     * @param string $code PHP code
     *
     * @return Tokens
     */
    public static function fromCode($code)
    {
        $codeHash = self::calculateCodeHash($code);

        if (self::hasCache($codeHash)) {
            $tokens = self::getCache($codeHash);

            // generate the code to recalculate the hash
            $tokens->generateCode();

            if ($codeHash === $tokens->codeHash) {
                $tokens->clearEmptyTokens();
                $tokens->clearChanged();

                return $tokens;
            }
        }

        $tokens = new self();
        $tokens->setCode($code);
        $tokens->clearChanged();

        return $tokens;
    }

    /**
     * @return array
     */
    public static function getBlockEdgeDefinitions()
    {
        return [
            self::BLOCK_TYPE_CURLY_BRACE => [
                'start' => '{',
                'end' => '}',
            ],
            self::BLOCK_TYPE_PARENTHESIS_BRACE => [
                'start' => '(',
                'end' => ')',
            ],
            self::BLOCK_TYPE_INDEX_SQUARE_BRACE => [
                'start' => '[',
                'end' => ']',
            ],
            self::BLOCK_TYPE_ARRAY_SQUARE_BRACE => [
                'start' => [CT::T_ARRAY_SQUARE_BRACE_OPEN, '['],
                'end' => [CT::T_ARRAY_SQUARE_BRACE_CLOSE, ']'],
            ],
            self::BLOCK_TYPE_DYNAMIC_PROP_BRACE => [
                'start' => [CT::T_DYNAMIC_PROP_BRACE_OPEN, '{'],
                'end' => [CT::T_DYNAMIC_PROP_BRACE_CLOSE, '}'],
            ],
            self::BLOCK_TYPE_DYNAMIC_VAR_BRACE => [
                'start' => [CT::T_DYNAMIC_VAR_BRACE_OPEN, '{'],
                'end' => [CT::T_DYNAMIC_VAR_BRACE_CLOSE, '}'],
            ],
            self::BLOCK_TYPE_ARRAY_INDEX_CURLY_BRACE => [
                'start' => [CT::T_ARRAY_INDEX_CURLY_BRACE_OPEN, '{'],
                'end' => [CT::T_ARRAY_INDEX_CURLY_BRACE_CLOSE, '}'],
            ],
            self::BLOCK_TYPE_GROUP_IMPORT_BRACE => [
                'start' => [CT::T_GROUP_IMPORT_BRACE_OPEN, '{'],
                'end' => [CT::T_GROUP_IMPORT_BRACE_CLOSE, '}'],
            ],
            self::BLOCK_TYPE_DESTRUCTURING_SQUARE_BRACE => [
                'start' => [CT::T_DESTRUCTURING_SQUARE_BRACE_OPEN, '['],
                'end' => [CT::T_DESTRUCTURING_SQUARE_BRACE_CLOSE, ']'],
            ],
            self::BLOCK_TYPE_BRACE_CLASS_INSTANTIATION => [
                'start' => [CT::T_BRACE_CLASS_INSTANTIATION_OPEN, '('],
                'end' => [CT::T_BRACE_CLASS_INSTANTIATION_CLOSE, ')'],
            ],
        ];
    }

    /**
     * Set new size of collection.
     *
     * @param int $size
     */
    public function setSize($size)
    {
        if ($this->getSize() !== $size) {
            $this->changed = true;
            parent::setSize($size);
        }
    }

    /**
     * Unset collection item.
     *
     * @param int $index
     */
    public function offsetUnset($index)
    {
        $this->changed = true;
        $this->unregisterFoundToken($this[$index]);
        parent::offsetUnset($index);
    }

    /**
     * Set collection item.
     *
     * Warning! `$newval` must not be typehinted to be compatible with `ArrayAccess::offsetSet` method.
     *
     * @param int   $index
     * @param Token $newval
     */
    public function offsetSet($index, $newval)
    {
        $this->blockEndCache = [];

        if (!$this[$index] || !$this[$index]->equals($newval)) {
            $this->changed = true;
        }

        if (isset($this[$index])) {
            $this->unregisterFoundToken($this[$index]);
        }

        $this->registerFoundToken($newval);
        parent::offsetSet($index, $newval);
    }

    /**
     * Clear internal flag if collection was changed and flag for all collection's items.
     */
    public function clearChanged()
    {
        $this->changed = false;
    }

    /**
     * Clear empty tokens.
     *
     * Empty tokens can occur e.g. after calling clear on item of collection.
     */
    public function clearEmptyTokens()
    {
        $limit = $this->count();
        $index = 0;

        for (; $index < $limit; ++$index) {
            if ($this->isEmptyAt($index)) {
                break;
            }
        }

        // no empty token found, therefore there is no need to override collection
        if ($limit === $index) {
            return;
        }

        for ($count = $index; $index < $limit; ++$index) {
            if (!$this->isEmptyAt($index)) {
                $this[$count++] = $this[$index];
            }
        }

        $this->setSize($count);
    }

    /**
     * Ensure that on given index is a whitespace with given kind.
     *
     * If there is a whitespace then it's content will be modified.
     * If not - the new Token will be added.
     *
     * @param int    $index       index
     * @param int    $indexOffset index offset for Token insertion
     * @param string $whitespace  whitespace to set
     *
     * @return bool if new Token was added
     */
    public function ensureWhitespaceAtIndex($index, $indexOffset, $whitespace)
    {
        $removeLastCommentLine = static function (self $tokens, $index, $indexOffset, $whitespace) {
            $token = $tokens[$index];

            if (1 === $indexOffset && $token->isGivenKind(T_OPEN_TAG)) {
                if (0 === strpos($whitespace, "\r\n")) {
                    $tokens[$index] = new Token([T_OPEN_TAG, rtrim($token->getContent())."\r\n"]);

                    return \strlen($whitespace) > 2 // can be removed on PHP 7; https://php.net/manual/en/function.substr.php
                        ? substr($whitespace, 2)
                        : ''
                    ;
                }

                $tokens[$index] = new Token([T_OPEN_TAG, rtrim($token->getContent()).$whitespace[0]]);

                return \strlen($whitespace) > 1 // can be removed on PHP 7; https://php.net/manual/en/function.substr.php
                    ? substr($whitespace, 1)
                    : ''
                ;
            }

            return $whitespace;
        };

        if ($this[$index]->isWhitespace()) {
            $whitespace = $removeLastCommentLine($this, $index - 1, $indexOffset, $whitespace);

            if ('' === $whitespace) {
                $this->clearAt($index);
            } else {
                $this[$index] = new Token([T_WHITESPACE, $whitespace]);
            }

            return false;
        }

        $whitespace = $removeLastCommentLine($this, $index, $indexOffset, $whitespace);
        if ('' === $whitespace) {
            return false;
        }

        $this->insertAt(
            $index + $indexOffset,
            [
                new Token([T_WHITESPACE, $whitespace]),
            ]
        );

        return true;
    }

    /**
     * @param int  $type        type of block, one of BLOCK_TYPE_*
     * @param int  $searchIndex index of opening brace
     * @param bool $findEnd     if method should find block's end, default true, otherwise method find block's start
     *
     * @return int index of closing brace
     */
    public function findBlockEnd($type, $searchIndex, $findEnd = true)
    {
        if (3 === \func_num_args()) {
            if ($findEnd) {
                @trigger_error('Argument #3 of Tokens::findBlockEnd is deprecated and will be removed in 3.0, you can safely drop the argument.', E_USER_DEPRECATED);
            } else {
                @trigger_error('Argument #3 of Tokens::findBlockEnd is deprecated and will be removed in 3.0, use Tokens::findBlockStart instead.', E_USER_DEPRECATED);
            }
        }

        return $this->findOppositeBlockEdge($type, $searchIndex, $findEnd);
    }

    /**
     * @param int $type        type of block, one of BLOCK_TYPE_*
     * @param int $searchIndex index of closing brace
     *
     * @return int index of opening brace
     */
    public function findBlockStart($type, $searchIndex)
    {
        return $this->findOppositeBlockEdge($type, $searchIndex, false);
    }

    /**
     * @param array|int $possibleKind kind or array of kind
     * @param int       $start        optional offset
     * @param null|int  $end          optional limit
     *
     * @return array array of tokens of given kinds or assoc array of arrays
     */
    public function findGivenKind($possibleKind, $start = 0, $end = null)
    {
        $this->rewind();
        if (null === $end) {
            $end = $this->count();
        }

        $elements = [];
        $possibleKinds = (array) $possibleKind;

        foreach ($possibleKinds as $kind) {
            $elements[$kind] = [];
        }

        $possibleKinds = array_filter($possibleKinds, function ($kind) {
            return $this->isTokenKindFound($kind);
        });

        if (\count($possibleKinds)) {
            for ($i = $start; $i < $end; ++$i) {
                $token = $this[$i];
                if ($token->isGivenKind($possibleKinds)) {
                    $elements[$token->getId()][$i] = $token;
                }
            }
        }

        return \is_array($possibleKind) ? $elements : $elements[$possibleKind];
    }

    /**
     * @return string
     */
    public function generateCode()
    {
        $code = $this->generatePartialCode(0, \count($this) - 1);
        $this->changeCodeHash(self::calculateCodeHash($code));

        return $code;
    }

    /**
     * Generate code from tokens between given indexes.
     *
     * @param int $start start index
     * @param int $end   end index
     *
     * @return string
     */
    public function generatePartialCode($start, $end)
    {
        $code = '';

        for ($i = $start; $i <= $end; ++$i) {
            $code .= $this[$i]->getContent();
        }

        return $code;
    }

    /**
     * Get hash of code.
     *
     * @return string
     */
    public function getCodeHash()
    {
        return $this->codeHash;
    }

    /**
     * Get index for closest next token which is non whitespace.
     *
     * This method is shorthand for getNonWhitespaceSibling method.
     *
     * @param int         $index       token index
     * @param null|string $whitespaces whitespaces characters for Token::isWhitespace
     *
     * @return null|int
     */
    public function getNextNonWhitespace($index, $whitespaces = null)
    {
        return $this->getNonWhitespaceSibling($index, 1, $whitespaces);
    }

    /**
     * Get index for closest next token of given kind.
     *
     * This method is shorthand for getTokenOfKindSibling method.
     *
     * @param int   $index         token index
     * @param array $tokens        possible tokens
     * @param bool  $caseSensitive perform a case sensitive comparison
     *
     * @return null|int
     */
    public function getNextTokenOfKind($index, array $tokens = [], $caseSensitive = true)
    {
        return $this->getTokenOfKindSibling($index, 1, $tokens, $caseSensitive);
    }

    /**
     * Get index for closest sibling token which is non whitespace.
     *
     * @param int         $index       token index
     * @param int         $direction   direction for looking, +1 or -1
     * @param null|string $whitespaces whitespaces characters for Token::isWhitespace
     *
     * @return null|int
     */
    public function getNonWhitespaceSibling($index, $direction, $whitespaces = null)
    {
        while (true) {
            $index += $direction;

            if (!$this->offsetExists($index)) {
                return null;
            }

            $token = $this[$index];

            if (!$token->isWhitespace($whitespaces)) {
                return $index;
            }
        }
    }

    /**
     * Get index for closest previous token which is non whitespace.
     *
     * This method is shorthand for getNonWhitespaceSibling method.
     *
     * @param int         $index       token index
     * @param null|string $whitespaces whitespaces characters for Token::isWhitespace
     *
     * @return null|int
     */
    public function getPrevNonWhitespace($index, $whitespaces = null)
    {
        return $this->getNonWhitespaceSibling($index, -1, $whitespaces);
    }

    /**
     * Get index for closest previous token of given kind.
     * This method is shorthand for getTokenOfKindSibling method.
     *
     * @param int   $index         token index
     * @param array $tokens        possible tokens
     * @param bool  $caseSensitive perform a case sensitive comparison
     *
     * @return null|int
     */
    public function getPrevTokenOfKind($index, array $tokens = [], $caseSensitive = true)
    {
        return $this->getTokenOfKindSibling($index, -1, $tokens, $caseSensitive);
    }

    /**
     * Get index for closest sibling token of given kind.
     *
     * @param int   $index         token index
     * @param int   $direction     direction for looking, +1 or -1
     * @param array $tokens        possible tokens
     * @param bool  $caseSensitive perform a case sensitive comparison
     *
     * @return null|int
     */
    public function getTokenOfKindSibling($index, $direction, array $tokens = [], $caseSensitive = true)
    {
        $tokens = array_filter($tokens, function ($token) {
            return $this->isTokenKindFound($this->extractTokenKind($token));
        });

        if (!\count($tokens)) {
            return null;
        }

        while (true) {
            $index += $direction;

            if (!$this->offsetExists($index)) {
                return null;
            }

            $token = $this[$index];

            if ($token->equalsAny($tokens, $caseSensitive)) {
                return $index;
            }
        }
    }

    /**
     * Get index for closest sibling token not of given kind.
     *
     * @param int   $index     token index
     * @param int   $direction direction for looking, +1 or -1
     * @param array $tokens    possible tokens
     *
     * @return null|int
     */
    public function getTokenNotOfKindSibling($index, $direction, array $tokens = [])
    {
        while (true) {
            $index += $direction;

            if (!$this->offsetExists($index)) {
                return null;
            }

            if ($this->isEmptyAt($index)) {
                continue;
            }

            if ($this[$index]->equalsAny($tokens)) {
                continue;
            }

            return $index;
        }
    }

    /**
     * Get index for closest sibling token that is not a whitespace or comment.
     *
     * @param int $index     token index
     * @param int $direction direction for looking, +1 or -1
     *
     * @return null|int
     */
    public function getMeaningfulTokenSibling($index, $direction)
    {
        return $this->getTokenNotOfKindSibling(
            $index,
            $direction,
            [[T_WHITESPACE], [T_COMMENT], [T_DOC_COMMENT]]
        );
    }

    /**
     * Get index for closest sibling token which is not empty.
     *
     * @param int $index     token index
     * @param int $direction direction for looking, +1 or -1
     *
     * @return null|int
     */
    public function getNonEmptySibling($index, $direction)
    {
        while (true) {
            $index += $direction;

            if (!$this->offsetExists($index)) {
                return null;
            }

            if (!$this->isEmptyAt($index)) {
                return $index;
            }
        }
    }

    /**
     * Get index for closest next token that is not a whitespace or comment.
     *
     * @param int $index token index
     *
     * @return null|int
     */
    public function getNextMeaningfulToken($index)
    {
        return $this->getMeaningfulTokenSibling($index, 1);
    }

    /**
     * Get index for closest previous token that is not a whitespace or comment.
     *
     * @param int $index token index
     *
     * @return null|int
     */
    public function getPrevMeaningfulToken($index)
    {
        return $this->getMeaningfulTokenSibling($index, -1);
    }

    /**
     * Find a sequence of meaningful tokens and returns the array of their locations.
     *
     * @param array                 $sequence      an array of tokens (kinds) (same format used by getNextTokenOfKind)
     * @param int                   $start         start index, defaulting to the start of the file
     * @param int                   $end           end index, defaulting to the end of the file
     * @param array<int, bool>|bool $caseSensitive global case sensitiveness or an array of booleans, whose keys should match
     *                                             the ones used in $others. If any is missing, the default case-sensitive
     *                                             comparison is used
     *
     * @return null|array<int, Token> an array containing the tokens matching the sequence elements, indexed by their position
     */
    public function findSequence(array $sequence, $start = 0, $end = null, $caseSensitive = true)
    {
        $sequenceCount = \count($sequence);
        if (0 === $sequenceCount) {
            throw new \InvalidArgumentException('Invalid sequence.');
        }

        // $end defaults to the end of the collection
        $end = null === $end ? \count($this) - 1 : min($end, \count($this) - 1);

        if ($start + $sequenceCount - 1 > $end) {
            return null;
        }

        // make sure the sequence content is "meaningful"
        foreach ($sequence as $key => $token) {
            // if not a Token instance already, we convert it to verify the meaningfulness
            if (!$token instanceof Token) {
                if (\is_array($token) && !isset($token[1])) {
                    // fake some content as it is required by the Token constructor,
                    // although optional for search purposes
                    $token[1] = 'DUMMY';
                }
                $token = new Token($token);
            }

            if ($token->isWhitespace() || $token->isComment() || '' === $token->getContent()) {
                throw new \InvalidArgumentException(sprintf('Non-meaningful token at position: %s.', $key));
            }
        }

        foreach ($sequence as $token) {
            if (!$this->isTokenKindFound($this->extractTokenKind($token))) {
                return null;
            }
        }

        // remove the first token from the sequence, so we can freely iterate through the sequence after a match to
        // the first one is found
        $key = key($sequence);
        $firstCs = Token::isKeyCaseSensitive($caseSensitive, $key);
        $firstToken = $sequence[$key];
        unset($sequence[$key]);

        // begin searching for the first token in the sequence (start included)
        $index = $start - 1;
        while (null !== $index && $index <= $end) {
            $index = $this->getNextTokenOfKind($index, [$firstToken], $firstCs);

            // ensure we found a match and didn't get past the end index
            if (null === $index || $index > $end) {
                return null;
            }

            // initialise the result array with the current index
            $result = [$index => $this[$index]];

            // advance cursor to the current position
            $currIdx = $index;

            // iterate through the remaining tokens in the sequence
            foreach ($sequence as $key => $token) {
                $currIdx = $this->getNextMeaningfulToken($currIdx);

                // ensure we didn't go too far
                if (null === $currIdx || $currIdx > $end) {
                    return null;
                }

                if (!$this[$currIdx]->equals($token, Token::isKeyCaseSensitive($caseSensitive, $key))) {
                    // not a match, restart the outer loop
                    continue 2;
                }

                // append index to the result array
                $result[$currIdx] = $this[$currIdx];
            }

            // do we have a complete match?
            // hint: $result is bigger than $sequence since the first token has been removed from the latter
            if (\count($sequence) < \count($result)) {
                return $result;
            }
        }
    }

    /**
     * Insert instances of Token inside collection.
     *
     * @param int                  $index start inserting index
     * @param Token|Token[]|Tokens $items instances of Token to insert
     */
    public function insertAt($index, $items)
    {
        $items = \is_array($items) || $items instanceof self ? $items : [$items];
        $itemsCnt = \count($items);

        if (0 === $itemsCnt) {
            return;
        }

        $oldSize = \count($this);
        $this->changed = true;
        $this->blockEndCache = [];
        $this->setSize($oldSize + $itemsCnt);

        // since we only move already existing items around, we directly call into SplFixedArray::offset* methods.
        // that way we get around additional overhead this class adds with overridden offset* methods.
        for ($i = $oldSize + $itemsCnt - 1; $i >= $index; --$i) {
            $oldItem = parent::offsetExists($i - $itemsCnt) ? parent::offsetGet($i - $itemsCnt) : new Token('');
            parent::offsetSet($i, $oldItem);
        }

        for ($i = 0; $i < $itemsCnt; ++$i) {
            if ('' === $items[$i]->getContent()) {
                throw new \InvalidArgumentException('Must not add empty token to collection.');
            }

            $this->registerFoundToken($items[$i]);
            parent::offsetSet($i + $index, $items[$i]);
        }
    }

    /**
     * Check if collection was change: collection itself (like insert new tokens) or any of collection's elements.
     *
     * @return bool
     */
    public function isChanged()
    {
        if ($this->changed) {
            return true;
        }

        return false;
    }

    /**
     * @param int $index
     *
     * @return bool
     */
    public function isEmptyAt($index)
    {
        $token = $this[$index];

        return null === $token->getId() && '' === $token->getContent();
    }

    public function clearAt($index)
    {
        $this[$index] = new Token('');
    }

    /**
     * Override tokens at given range.
     *
     * @param int            $indexStart start overriding index
     * @param int            $indexEnd   end overriding index
     * @param Token[]|Tokens $items      tokens to insert
     */
    public function overrideRange($indexStart, $indexEnd, $items)
    {
        $oldCode = $this->generatePartialCode($indexStart, $indexEnd);

        $newCode = '';
        foreach ($items as $item) {
            $newCode .= $item->getContent();
        }

        // no changes, return
        if ($oldCode === $newCode) {
            return;
        }

        $indexToChange = $indexEnd - $indexStart + 1;
        $itemsCount = \count($items);

        // If we want to add more items than passed range contains we need to
        // add placeholders for overhead items.
        if ($itemsCount > $indexToChange) {
            $placeholders = [];
            while ($itemsCount > $indexToChange) {
                $placeholders[] = new Token('__PLACEHOLDER__');
                ++$indexToChange;
            }
            $this->insertAt($indexEnd + 1, $placeholders);
        }

        // Override each items.
        foreach ($items as $itemIndex => $item) {
            $this[$indexStart + $itemIndex] = $item;
        }

        // If we want to add less tokens than passed range contains then clear
        // not needed tokens.
        if ($itemsCount < $indexToChange) {
            $this->clearRange($indexStart + $itemsCount, $indexEnd);
        }
    }

    /**
     * @param int         $index
     * @param null|string $whitespaces optional whitespaces characters for Token::isWhitespace
     */
    public function removeLeadingWhitespace($index, $whitespaces = null)
    {
        $this->removeWhitespaceSafely($index, 1, $whitespaces);
    }

    /**
     * @param int         $index
     * @param null|string $whitespaces optional whitespaces characters for Token::isWhitespace
     */
    public function removeTrailingWhitespace($index, $whitespaces = null)
    {
        $this->removeWhitespaceSafely($index, -1, $whitespaces);
    }

    /**
     * Set code. Clear all current content and replace it by new Token items generated from code directly.
     *
     * @param string $code PHP code
     */
    public function setCode($code)
    {
        // No need to work when the code is the same.
        // That is how we avoid a lot of work and setting changed flag.
        if ($code === $this->generateCode()) {
            return;
        }

        // clear memory
        $this->setSize(0);

        $tokens = \defined('TOKEN_PARSE')
            ? token_get_all($code, TOKEN_PARSE)
            : token_get_all($code);

        $this->setSize(\count($tokens));

        foreach ($tokens as $index => $token) {
            $this[$index] = new Token($token);
        }

        $transformers = Transformers::create();
        $transformers->transform($this);

        $this->foundTokenKinds = [];
        foreach ($this as $token) {
            $this->registerFoundToken($token);
        }

        $this->rewind();
        $this->changeCodeHash(self::calculateCodeHash($code));
        $this->changed = true;
    }

    public function toJson()
    {
        static $options = null;

        if (null === $options) {
            $options = Utils::calculateBitmask(['JSON_PRETTY_PRINT', 'JSON_NUMERIC_CHECK']);
        }

        $output = new \SplFixedArray(\count($this));

        foreach ($this as $index => $token) {
            $output[$index] = $token->toArray();
        }

        $this->rewind();

        return json_encode($output, $options);
    }

    /**
     * Check if all token kinds given as argument are found.
     *
     * @param array $tokenKinds
     *
     * @return bool
     */
    public function isAllTokenKindsFound(array $tokenKinds)
    {
        foreach ($tokenKinds as $tokenKind) {
            if (empty($this->foundTokenKinds[$tokenKind])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if any token kind given as argument is found.
     *
     * @param array $tokenKinds
     *
     * @return bool
     */
    public function isAnyTokenKindsFound(array $tokenKinds)
    {
        foreach ($tokenKinds as $tokenKind) {
            if (!empty($this->foundTokenKinds[$tokenKind])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if token kind given as argument is found.
     *
     * @param int|string $tokenKind
     *
     * @return bool
     */
    public function isTokenKindFound($tokenKind)
    {
        return !empty($this->foundTokenKinds[$tokenKind]);
    }

    /**
     * @param int|string $tokenKind
     *
     * @return int
     */
    public function countTokenKind($tokenKind)
    {
        return isset($this->foundTokenKinds[$tokenKind]) ? $this->foundTokenKinds[$tokenKind] : 0;
    }

    /**
     * Clear tokens in the given range.
     *
     * @param int $indexStart
     * @param int $indexEnd
     */
    public function clearRange($indexStart, $indexEnd)
    {
        for ($i = $indexStart; $i <= $indexEnd; ++$i) {
            $this->clearAt($i);
        }
    }

    /**
     * Checks for monolithic PHP code.
     *
     * Checks that the code is pure PHP code, in a single code block, starting
     * with an open tag.
     *
     * @return bool
     */
    public function isMonolithicPhp()
    {
        $size = $this->count();

        if (0 === $size) {
            return false;
        }

        if ($this->isTokenKindFound(T_INLINE_HTML)) {
            return false;
        }

        return 1 >= ($this->countTokenKind(T_OPEN_TAG) + $this->countTokenKind(T_OPEN_TAG_WITH_ECHO));
    }

    /**
     * @param int $start start index
     * @param int $end   end index
     *
     * @return bool
     */
    public function isPartialCodeMultiline($start, $end)
    {
        for ($i = $start; $i <= $end; ++$i) {
            if (false !== strpos($this[$i]->getContent(), "\n")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int $index
     */
    public function clearTokenAndMergeSurroundingWhitespace($index)
    {
        $count = \count($this);
        $this->clearAt($index);

        if ($index === $count - 1) {
            return;
        }

        $nextIndex = $this->getNonEmptySibling($index, 1);

        if (null === $nextIndex || !$this[$nextIndex]->isWhitespace()) {
            return;
        }

        $prevIndex = $this->getNonEmptySibling($index, -1);

        if ($this[$prevIndex]->isWhitespace()) {
            $this[$prevIndex] = new Token([T_WHITESPACE, $this[$prevIndex]->getContent().$this[$nextIndex]->getContent()]);
        } elseif ($this->isEmptyAt($prevIndex + 1)) {
            $this[$prevIndex + 1] = new Token([T_WHITESPACE, $this[$nextIndex]->getContent()]);
        }

        $this->clearAt($nextIndex);
    }

    private function removeWhitespaceSafely($index, $offset, $whitespaces = null)
    {
        if (isset($this[$index - $offset]) && $this[$index - $offset]->isWhitespace()) {
            $newContent = '';
            $tokenToCheck = $this[$index - $offset];

            // if the token candidate to remove is preceded by single line comment we do not consider the new line after this comment as part of T_WHITESPACE
            if (isset($this[$index - $offset - 1]) && $this[$index - $offset - 1]->isComment() && '/*' !== substr($this[$index - $offset - 1]->getContent(), 0, 2)) {
                list($emptyString, $newContent, $whitespacesToCheck) = Preg::split('/^(\R)/', $this[$index - $offset]->getContent(), -1, PREG_SPLIT_DELIM_CAPTURE);
                if ('' === $whitespacesToCheck) {
                    return;
                }
                $tokenToCheck = new Token([T_WHITESPACE, $whitespacesToCheck]);
            }

            if (!$tokenToCheck->isWhitespace($whitespaces)) {
                return;
            }

            if ('' === $newContent) {
                $this->clearAt($index - $offset);
            } else {
                $this[$index - $offset] = new Token([T_WHITESPACE, $newContent]);
            }
        }
    }

    /**
     * @param int  $type        type of block, one of BLOCK_TYPE_*
     * @param int  $searchIndex index of starting brace
     * @param bool $findEnd     if method should find block's end or start
     *
     * @return int index of opposite brace
     */
    private function findOppositeBlockEdge($type, $searchIndex, $findEnd)
    {
        $blockEdgeDefinitions = self::getBlockEdgeDefinitions();

        if (!isset($blockEdgeDefinitions[$type])) {
            throw new \InvalidArgumentException(sprintf('Invalid param type: %s.', $type));
        }

        if (isset($this->blockEndCache[$searchIndex])) {
            return $this->blockEndCache[$searchIndex];
        }

        $startEdge = $blockEdgeDefinitions[$type]['start'];
        $endEdge = $blockEdgeDefinitions[$type]['end'];
        $startIndex = $searchIndex;
        $endIndex = $this->count() - 1;
        $indexOffset = 1;

        if (!$findEnd) {
            list($startEdge, $endEdge) = [$endEdge, $startEdge];
            $indexOffset = -1;
            $endIndex = 0;
        }

        if (!$this[$startIndex]->equals($startEdge)) {
            throw new \InvalidArgumentException(sprintf('Invalid param $startIndex - not a proper block %s.', $findEnd ? 'start' : 'end'));
        }

        $blockLevel = 0;

        for ($index = $startIndex; $index !== $endIndex; $index += $indexOffset) {
            $token = $this[$index];

            if ($token->equals($startEdge)) {
                ++$blockLevel;

                continue;
            }

            if ($token->equals($endEdge)) {
                --$blockLevel;

                if (0 === $blockLevel) {
                    break;
                }

                continue;
            }
        }

        if (!$this[$index]->equals($endEdge)) {
            throw new \UnexpectedValueException(sprintf('Missing block %s.', $findEnd ? 'end' : 'start'));
        }

        $this->blockEndCache[$startIndex] = $index;
        $this->blockEndCache[$index] = $startIndex;

        return $index;
    }

    /**
     * Calculate hash for code.
     *
     * @param string $code
     *
     * @return string
     */
    private static function calculateCodeHash($code)
    {
        return CodeHasher::calculateCodeHash($code);
    }

    /**
     * Get cache value for given key.
     *
     * @param string $key item key
     *
     * @return Tokens
     */
    private static function getCache($key)
    {
        if (!self::hasCache($key)) {
            throw new \OutOfBoundsException(sprintf('Unknown cache key: "%s".', $key));
        }

        return self::$cache[$key];
    }

    /**
     * Check if given key exists in cache.
     *
     * @param string $key item key
     *
     * @return bool
     */
    private static function hasCache($key)
    {
        return isset(self::$cache[$key]);
    }

    /**
     * @param string $key   item key
     * @param Tokens $value item value
     */
    private static function setCache($key, self $value)
    {
        self::$cache[$key] = $value;
    }

    /**
     * Change code hash.
     *
     * Remove old cache and set new one.
     *
     * @param string $codeHash new code hash
     */
    private function changeCodeHash($codeHash)
    {
        if (null !== $this->codeHash) {
            self::clearCache($this->codeHash);
        }

        $this->codeHash = $codeHash;
        self::setCache($this->codeHash, $this);
    }

    /**
     * Register token as found.
     *
     * @param array|string|Token $token token prototype
     */
    private function registerFoundToken($token)
    {
        $tokenKind = $this->extractTokenKind($token);

        if (!isset($this->foundTokenKinds[$tokenKind])) {
            $this->foundTokenKinds[$tokenKind] = 0;
        }

        ++$this->foundTokenKinds[$tokenKind];
    }

    /**
     * Register token as found.
     *
     * @param array|string|Token $token token prototype
     */
    private function unregisterFoundToken($token)
    {
        $tokenKind = $this->extractTokenKind($token);

        if (!isset($this->foundTokenKinds[$tokenKind])) {
            return;
        }

        --$this->foundTokenKinds[$tokenKind];
    }

    /**
     * @param array|string|Token $token token prototype
     *
     * @return int|string
     */
    private function extractTokenKind($token)
    {
        return $token instanceof Token
            ? ($token->isArray() ? $token->getId() : $token->getContent())
            : (\is_array($token) ? $token[0] : $token)
        ;
    }
}
