<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Transformer;

/**
 * 2D tensor (row-major float matrix) used by the Transformer math.
 *
 * WHAT IS THIS?
 * --------------
 * Imagine a spreadsheet. A 2D tensor is just a grid of numbers with
 * a fixed number of rows and columns. "Row-major" means we lay the
 * numbers out in memory row by row, left to right, like reading a book.
 * So a 2x3 matrix like:
 *     [ 1 2 3 ]
 *     [ 4 5 6 ]
 * is stored in the flat list as: [1, 2, 3, 4, 5, 6].
 *
 * WHY A CLASS?
 * ------------
 * Neural networks do lots of matrix math: add, multiply, transpose.
 * Wrapping this in a class makes the code easier to read and stops us
 * from accidentally mixing up shapes (e.g. multiplying a 2x3 by a 4x5
 * which doesn't fit together).
 *
 * MUTABILITY NOTE
 * ---------------
 * The internal data array is mutable to allow fast in-place updates
 * in the math layer (we sometimes need to scatter gradients into a
 * pre-allocated matrix, which is faster than copying). The public
 * arithmetic methods (add, sub, matmul, ...) return NEW Tensor objects
 * so callers don't accidentally share state.
 */
final class Tensor
{
    /**
     * The actual numbers, stored one row after another.
     * Example for a 2x3 matrix:
     *   index 0 -> row 0, col 0
     *   index 1 -> row 0, col 1
     *   index 2 -> row 0, col 2
     *   index 3 -> row 1, col 0
     *   index 4 -> row 1, col 1
     *   index 5 -> row 1, col 2
     *
     * @var list<float>
     */
    private array $data;

    /**
     * Build a new tensor.
     *
     * WHAT HAPPENS HERE:
     * 1. We check that rows and cols are not negative (a tensor can't
     *    have a negative size, that would be nonsense).
     * 2. We count how many numbers we expect: rows * cols.
     * 3. We turn whatever the caller passed in (an array, a generator,
     *    anything "iterable") into a plain list of floats.
     * 4. We check the list has exactly the right number of numbers;
     *    if not, we refuse to build the tensor (a 2x3 tensor must
     *    contain exactly 6 numbers, no more, no less).
     *
     * @param iterable<float> $data the flat list of numbers, row by row
     */
    public function __construct(
        public readonly int $rows,   // how many rows the matrix has
        public readonly int $cols,   // how many columns the matrix has
        iterable $data = [],        // the actual numbers
    ) {
        // Sanity check: a tensor can't have negative size.
        if ($rows < 0 || $cols < 0) {
            throw new \InvalidArgumentException("Tensor dimensions must be non-negative, got ({$rows}, {$cols}).");
        }
        // The total number of values we need.
        $expected = $rows * $cols;
        // Copy whatever was passed in (could be array, could be a
        // generator) into a plain PHP array of floats.
        $flat = [];
        foreach ($data as $v) {
            $flat[] = (float) $v;
        }
        // The list must have exactly rows*cols values. If not, we have
        // a bug somewhere and we'd rather know now than later.
        if (\count($flat) !== $expected) {
            throw new \InvalidArgumentException(sprintf(
                'Tensor data length (%d) does not match rows*cols (%d*%d = %d).',
                \count($flat),
                $rows,
                $cols,
                $expected,
            ));
        }
        $this->data = $flat;
    }

    /**
     * Make a tensor full of zeros. Like an empty spreadsheet.
     *
     * WHY: We often need a starting point, for example to accumulate
     * gradients (we start with 0 and add to it).
     */
    public static function zeros(int $rows, int $cols): self
    {
        // array_fill creates a list of 0.0 values, of the right length.
        return new self($rows, $cols, array_fill(0, $rows * $cols, 0.0));
    }

    /**
     * Build a tensor from a 2D PHP array (a list of rows).
     *
     * EXAMPLE INPUT:
     *   [ [1, 2, 3], [4, 5, 6] ]
     * RESULT:
     *   2x3 tensor with data [1, 2, 3, 4, 5, 6].
     *
     * @param array<int, array<int, float|int>> $matrix rows of numbers
     */
    public static function fromMatrix(array $matrix): self
    {
        // Count rows and columns from the input array.
        $rows = \count($matrix);
        // If there is at least one row, its length tells us how many
        // columns we have. Empty input -> 0 columns.
        $cols = $rows > 0 ? \count($matrix[0]) : 0;
        // Flatten: take all numbers out of the nested arrays and put
        // them one after another (row-major order).
        $flat = [];
        foreach ($matrix as $row) {
            foreach ($row as $v) {
                $flat[] = (float) $v;
            }
        }

        return new self($rows, $cols, $flat);
    }

    /**
     * Return the shape as [rows, cols]. Like asking the spreadsheet
     * "how big are you?".
     *
     * @return array{int, int}
     */
    public function shape(): array
    {
        return [$this->rows, $this->cols];
    }

    /**
     * Internal mutable data accessor (by reference). The math layer
     * uses this for in-place operations; outside callers should treat
     * the returned array as read-only.
     *
     * WHY "BY REFERENCE"?
     * Returning by reference (&) means the caller gets the actual
     * array, not a copy. That way they can change values inside it
     * without us having to write them back. It's a small optimization
     * for the math layer that needs to scatter gradients fast.
     *
     * @return list<float>
     */
    public function &data(): array
    {
        return $this->data;
    }

    /**
     * Read a single number at row i, column j.
     *
     * WHY: The flat array stores numbers in row-major order, so the
     * position of element (i, j) is: i * cols + j. Like in a school
     * photo, student #5 in row #2 is the 5 + 2*cols-th person in line.
     */
    public function at(int $i, int $j): float
    {
        // Check the indices are inside the matrix. Asking for (5,5) on
        // a 2x3 matrix would silently read garbage memory without this.
        if ($i < 0 || $i >= $this->rows || $j < 0 || $j >= $this->cols) {
            throw new \OutOfBoundsException("Tensor index ($i, $j) out of bounds for ({$this->rows}, {$this->cols}).");
        }

        return $this->data[$i * $this->cols + $j];
    }

    /**
     * Read an entire row as a flat list of floats.
     *
     * EXAMPLE: for a 2x3 tensor [1,2,3,4,5,6], row(1) returns [4,5,6].
     *
     * @return list<float>
     */
    public function row(int $i): array
    {
        // Same bounds check as at().
        if ($i < 0 || $i >= $this->rows) {
            throw new \OutOfBoundsException("Row $i out of bounds for {$this->rows} rows.");
        }
        // array_slice copies a piece of the flat list. Each row has
        // exactly $cols numbers, and row i starts at position i*cols.
        return \array_slice($this->data, $i * $this->cols, $this->cols);
    }

    /**
     * Swap rows and columns.
     *
     * EXAMPLE: a 2x3 matrix
     *     [ 1 2 3 ]
     *     [ 4 5 6 ]
     * becomes a 3x2 matrix
     *     [ 1 4 ]
     *     [ 2 5 ]
     *     [ 3 6 ]
     *
     * WHY: Matrix multiplication A @ B needs B's columns to match A's
     * rows, so we often have to transpose one of them.
     */
    public function transpose(): self
    {
        // Allocate the new flat list with the transposed shape.
        $out = array_fill(0, $this->cols * $this->rows, 0.0);
        // Walk every (i, j) and put it at (j, i) in the new matrix.
        for ($i = 0; $i < $this->rows; $i++) {
            for ($j = 0; $j < $this->cols; $j++) {
                $out[$j * $this->rows + $i] = $this->data[$i * $this->cols + $j];
            }
        }

        return new self($this->cols, $this->rows, $out);
    }

    /**
     * Element-wise addition: out[i,j] = this[i,j] + other[i,j].
     *
     * Both tensors must be the same size (you can't add a 2x3 to a 3x2).
     */
    public function add(self $other): self
    {
        $this->checkShape($other, 'add');
        $out = [];
        foreach ($this->data as $k => $v) {
            $out[] = $v + $other->data[$k];
        }

        return new self($this->rows, $this->cols, $out);
    }

    /**
     * Element-wise subtraction: out[i,j] = this[i,j] - other[i,j].
     */
    public function sub(self $other): self
    {
        $this->checkShape($other, 'sub');
        $out = [];
        foreach ($this->data as $k => $v) {
            $out[] = $v - $other->data[$k];
        }

        return new self($this->rows, $this->cols, $out);
    }

    /**
     * Multiply every number in the tensor by a single number.
     * This is sometimes called "scaling".
     *
     * WHY: We use it to divide by 1/sqrt(dModel) in the attention
     * scores, or to apply a learning rate to gradients.
     */
    public function scale(float $scalar): self
    {
        $out = [];
        foreach ($this->data as $v) {
            $out[] = $v * $scalar;
        }

        return new self($this->rows, $this->cols, $out);
    }

    /**
     * Element-wise multiplication (Hadamard).
     *
     * "Hadamard" is a fancy word that just means "multiply matching
     * positions". out[i,j] = this[i,j] * other[i,j].
     *
     * WHY: We use it to apply a mask (multiply by 1 to keep, 0 to drop)
     * in things like the ReLU backward pass and causal attention.
     */
    public function mul(self $other): self
    {
        $this->checkShape($other, 'mul');
        $out = [];
        foreach ($this->data as $k => $v) {
            $out[] = $v * $other->data[$k];
        }

        return new self($this->rows, $this->cols, $out);
    }

    /**
     * Matrix multiplication: (rows, k) @ (k, cols) -> (rows, cols).
     *
     * SIMPLE EXPLANATION:
     * Multiplying two matrices is like doing a big dot-product puzzle.
     * For every cell (i, j) of the result, we look at row i of the
     * first matrix and column j of the second matrix, multiply
     * matching pairs, and add them up.
     *
     * SHAPE RULE:
     * The first matrix has shape (A rows, K cols).
     * The second matrix has shape (K rows, B cols).
     * The middle numbers (K) must match, otherwise we can't pair them
     * up. The result has shape (A rows, B cols).
     *
     * WHY: The whole Transformer is built out of matrix multiplies.
     * Q, K, V, attention scores, the final projection -- all matmul.
     *
     * SMALL OPTIMIZATION:
     * If a value in A is exactly 0, we skip its inner loop. Many
     * matrices (like the causal mask) are full of zeros, so this
     * saves real time.
     */
    public function matmul(self $other): self
    {
        if ($this->cols !== $other->rows) {
            throw new \InvalidArgumentException(
                "matmul shape mismatch: ({$this->rows}, {$this->cols}) @ ({$other->rows}, {$other->cols})."
            );
        }
        // Make an output matrix full of zeros. We'll add to it.
        $out = array_fill(0, $this->rows * $other->cols, 0.0);
        for ($i = 0; $i < $this->rows; $i++) {
            for ($k = 0; $k < $this->cols; $k++) {
                // Pick one number from A. If it's 0, skip the work.
                $a = $this->data[$i * $this->cols + $k];
                if ($a === 0.0) {
                    continue;
                }
                // Multiply by every entry in row k of B, and add to
                // the matching output column.
                for ($j = 0; $j < $other->cols; $j++) {
                    $out[$i * $other->cols + $j] += $a * $other->data[$k * $other->cols + $j];
                }
            }
        }

        return new self($this->rows, $other->cols, $out);
    }

    /**
     * Apply a function to every number in the tensor.
     *
     * EXAMPLE: apply(exp) makes every cell the exponential of itself.
     * This is the heart of the softmax trick.
     */
    public function apply(callable $fn): self
    {
        $out = [];
        foreach ($this->data as $v) {
            $out[] = $fn($v);
        }

        return new self($this->rows, $this->cols, $out);
    }

    /**
     * Add all numbers together. Gives one big float.
     */
    public function sum(): float
    {
        return array_sum($this->data);
    }

    /**
     * The average of all numbers.
     */
    public function mean(): float
    {
        if ($this->data === []) {
            return 0.0;
        }

        return array_sum($this->data) / \count($this->data);
    }

    /**
     * The biggest number in the tensor.
     */
    public function max(): float
    {
        if ($this->data === []) {
            throw new \RuntimeException('Cannot take max of empty tensor.');
        }

        return max($this->data);
    }

    /**
     * Turn the tensor back into a regular 2D PHP array.
     *
     * @return array<int, array<int, float>>
     */
    public function toMatrix(): array
    {
        $out = [];
        for ($i = 0; $i < $this->rows; $i++) {
            $out[] = \array_slice($this->data, $i * $this->cols, $this->cols);
        }

        return $out;
    }

    /**
     * Test if two tensors have the same numbers (within a tiny error).
     * Used in tests to check that our math agrees with the textbook.
     */
    public function equals(self $other, float $eps = 1e-9): bool
    {
        if ($this->rows !== $other->rows || $this->cols !== $other->cols) {
            return false;
        }
        foreach ($this->data as $k => $v) {
            if (\abs($v - $other->data[$k]) > $eps) {
                return false;
            }
        }

        return true;
    }

    /**
     * Private helper: refuse to do math if the two tensors are different
     * sizes. Like trying to add "3 apples + 4 oranges" -- the answer
     * is meaningless, so we shout.
     */
    private function checkShape(self $other, string $op): void
    {
        if ($this->rows !== $other->rows || $this->cols !== $other->cols) {
            throw new \InvalidArgumentException(
                "$op shape mismatch: ({$this->rows}, {$this->cols}) vs ({$other->rows}, {$other->cols})."
            );
        }
    }
}
