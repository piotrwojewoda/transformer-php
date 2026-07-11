# Decisions Log

A short, plain-English record of every significant decision and the alternatives considered. Refer to this when a teammate asks "why did we do it this way?" or when revisiting a choice six months from now.

The full table is in `SPECKIT.md §20`. This file is the narrative companion.

## D1 — Character-level tokenization

**Choice:** Tokenize at the unicode codepoint level.
**Why:** Simplest possible implementation; vocabulary stays below 100 even for a 200-character corpus; the entire tokenizer fits in ~80 lines. This is a teaching project, not a production LLM.
**Tradeoff:** Sequences are longer (one token per character). Real LMs use BPE or sentencepiece. We trade realism for clarity.
**Reversibility:** High. The `TokenizerPort` interface is a one-method swap away from a different tokenizer.

## D2 — Hand-derived gradients (no autograd)

**Choice:** Write the backward pass by hand for every layer, with finite-difference tests proving correctness.
**Why:** "Attention Is All You Need" is most educational when the reader can see the chain rule applied to attention, softmax, layer norm, etc. A built-in autograd would hide the math.
**Tradeoff:** More code, more bugs. We mitigate with finite-difference gradient tests.
**Reversibility:** Low. Replacing hand-derived gradients with a generic autograd is a significant refactor.

## D3 — One row per matrix element in MariaDB

**Choice:** Every scalar weight has its own row.
**Why:** Lets the developer inspect a real matrix with `SELECT * FROM model_attention_weights WHERE model_id = 1 AND layer = 0 AND matrix = 'wq' ORDER BY row, col`. Lets the developer reset a single layer with one `DELETE`. Makes the DB a first-class teaching artifact.
**Tradeoff:** Slow for real training. We train on tiny models with < 2 000 rows, so it is fine.
**Reversibility:** Medium. A `Weights` value object is a thin facade; swapping storage to a JSON column or a BLOB is mechanical.

## D4 — PHP 8.5 + Symfony 8.1

**Choice:** PHP 8.5 (latest) with Symfony 8.1 (current stable).
**Why:** User asked for PHP 8.5 and "the latest Symfony". Symfony 8.1 declares `php: ^8.4` which admits 8.5.
**Tradeoff:** PHP 8.5 is new; some libraries may not have caught up. Symfony 8.1 is fine on 8.5.
**Alternatives:** Symfony 7.4 LTS (also works on 8.5) or Symfony 8.2 (under development at time of writing). We chose 8.1 for the most current stable.

## D5 — Pre-norm attention

**Choice:** `x = x + Attention(LayerNorm(x))` instead of post-norm.
**Why:** Pre-norm has simpler gradient flow, fewer warm-up requirements, and is what modern Transformers use. It is also easier to teach because there is no "where do the residuals go" question.
**Tradeoff:** Slightly different output distribution than the original paper.
**Reversibility:** High. A `LayerNorm` placement flag in `ModelConfig` could switch.

## D6 — Single-head attention by default

**Choice:** `numHeads = 1` is the default.
**Why:** With `dModel = 8`, a single head already has 8 dimensions to mix. Multi-head with 8-dim heads (`numHeads = 8`) is mathematically equivalent at this scale and adds a `reshape` that distracts from the core attention math.
**Tradeoff:** Multi-head is a flagship feature of the paper. We expose it via the config; the user can set `numHeads = 2` or `4` and the code works.
**Reversibility:** Trivial; it is already implemented.

## D7 — Adam without bias correction

**Choice:** Standard Adam with `β1=0.9, β2=0.999, ε=1e-8`, but skipping the `1 - β^t` correction in the denominator.
**Why:** Bias correction adds two lines of code for an effect that is negligible at our scale (tens of epochs, lr=0.005). Skipping keeps the update step on screen in one line.
**Tradeoff:** Slightly biased early updates. Not visible in our setup.
**Reversibility:** Trivial; add two lines.

## D8 — ReLU FFN

**Choice:** `FFN(x) = ReLU(x·W1 + b1)·W2 + b2`.
**Why:** Simplest non-linearity. The point of the FFN is to introduce non-linearity between attention blocks; ReLU is the textbook choice.
**Tradeoff:** GeLU and SwiGLU perform better in practice. Not relevant for a teaching project.
**Reversibility:** Trivial.

## D9 — XML Doctrine mappings, not PHP attributes

**Choice:** All entity mappings live in `*.orm.xml` files.
**Why:** Cleaner for projects with many small tables (we have 13+); no annotation imports polluting the entities; one centralized place to see the schema.
**Tradeoff:** Slightly more boilerplate per entity.
**Reversibility:** Trivial; `doctrine:mapping:convert` can switch.

## D10 — `dama/doctrine-test-bundle` for tests

**Choice:** Wrap every test in a transaction that is rolled back at the end.
**Why:** Integration tests against MariaDB become fast (no truncate) and isolated (no cross-test pollution).
**Tradeoff:** A test that uses two database connections will not work. We only use one.
**Reversibility:** Trivial.

## D11 — `zenstruck/foundry` for test factories

**Choice:** Use Foundry to create entities in tests.
**Why:** Less boilerplate than hand-written factories; traits for `create()`, `findBy()`, etc.
**Tradeoff:** Slight learning curve for contributors who have not used it.
**Reversibility:** Trivial.

## D12 — Two message buses, CQRS-style

**Choice:** `command.bus` (with `doctrine_transaction`, `dispatch_after_current_bus`, `EventRecording` middleware) and `query.bus` (synchronous, no middleware).
**Why:** Textbook CQRS. Commands may be async (training, prediction); queries are always sync. The split makes the code reading "what is a command, what is a query" obvious from the directory layout alone.
**Tradeoff:** Slight over-engineering for a project this small. We accept it because the user asked for CQRS explicitly.
**Reversibility:** High.

## D13 — Pcov for coverage, Xdebug only for step debugging

**Choice:** Pcov is the default coverage driver; Xdebug is enabled only when the user starts a "Debug ..." VS Code configuration.
**Why:** Pcov is 5-10x faster than Xdebug for coverage and does not interfere with the step debugger (you cannot have Xdebug in coverage mode and step mode at the same time).
**Tradeoff:** Pcov and Xdebug cannot both be loaded into the same PHP process. The `php.ini` enables Xdebug, the test container uses Pcov.
**Reversibility:** Trivial.

## D14 — Tailwind via CDN, no build step

**Choice:** Templates pull Tailwind from `https://cdn.tailwindcss.com`.
**Why:** Zero JS toolchain. The user can `git clone && docker compose up` and have a styled UI in 30 seconds.
**Tradeoff:** No purging; slightly larger payload; the `cdn.tailwindcss.com` script is not for production. We are not in production.
**Reversibility:** Trivial.

## D15 — Re-dispatch `TrainModelMessage` per epoch

**Choice:** The training handler trains one epoch, persists, then re-dispatches a new `TrainModelMessage` to the queue if more epochs remain.
**Why:** Lets the developer kill the worker between epochs and inspect the persisted state. Makes step debugging natural (one epoch = one handler invocation). The state lives in `training_jobs` and `training_loss_history`, not in worker memory.
**Tradeoff:** Slightly more message overhead. Negligible at our scale.
**Reversibility:** Trivial.

## D16 — Test names: `testMethodName_doesWhat_whenCondition`

**Choice:** Camel-case test methods, snake-case descriptions, three parts.
**Why:** Reads like English (`testTrain_decreasesLoss_whenCorpusIsRepeatable`). Easy to grep.
**Tradeoff:** None.
**Reversibility:** Trivial; rename only.

## D17 — English-only documentation and UI

**Choice:** All SPECKIT, READMEs, code comments, and UI strings in English.
**Why:** User asked. Also: the project is a teaching artifact, English maximises the audience.
**Tradeoff:** A Polish-speaking user may want Polish UI. We add an empty `translations/` directory so Symfony's translation system can be plugged in later.
**Reversibility:** Trivial; add `symfony/translation` and a `messages.pl.yaml`.

## D18 — No `sleep()` in tests

**Choice:** All async behavior is asserted via `InMemoryTransport` or `WorkerTrait::runWorker()`.
**Why:** `sleep` makes the suite slow and flaky. The Symfony Messenger test utilities let us assert "this message was dispatched" and "this handler ran" deterministically.
**Tradeoff:** None.
**Reversibility:** Trivial.

## D19 — `Clock` port, no `new \DateTimeImmutable()` outside `SystemClock`

**Choice:** Every time-producing class receives a `Clock` via constructor.
**Why:** Tests use `MockClock` and assert on time deterministically. Production uses `SystemClock`.
**Tradeoff:** One more constructor parameter on a few classes.
**Reversibility:** Trivial.

## D20 — Random number generation via `Random\Randomizer`

**Choice:** We use PHP 8.2+ `Random\Randomizer` (the new, object-oriented, seedable RNG) for all weight initialization and sampling.
**Why:** Seedable, deterministic, faster than `mt_rand`, and the modern PHP idiom.
**Tradeoff:** Slightly more verbose than `mt_rand(0, 1)`.
**Reversibility:** Trivial.
