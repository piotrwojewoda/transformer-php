# SPECKIT — Hexagonal Symfony 8.1 / PHP 8.5 "Attention Is All You Need" Teaching Transformer

> **Status:** Ready for implementation.
> **Audience:** PHP / Symfony developers who want to read a real Transformer language model line by line with a step debugger.
> **Goal of the document:** A single, self-contained specification that an LLM or a human can implement top-to-bottom without guessing.

---

## Table of Contents

1. [Purpose & Non-Goals](#1-purpose--non-goals)
2. [Tech Stack](#2-tech-stack)
3. [Architecture Overview](#3-architecture-overview)
4. [Bounded Contexts](#4-bounded-contexts)
5. [Hexagonal Layers](#5-hexagonal-layers)
6. [Repository Layout](#6-repository-layout)
7. [Domain Model](#7-domain-model)
8. [Application Layer (CQRS)](#8-application-layer-cqrs)
9. [Infrastructure Layer](#9-infrastructure-layer)
10. [Database Schema (MariaDB)](#10-database-schema-mariadb)
11. [Messenger Setup](#11-messenger-setup)
12. [Transformer Math (no external library)](#12-transformer-math-no-external-library)
13. [HTTP / UI Layer](#13-http--ui-layer)
14. [Docker Setup](#14-docker-setup)
15. [Xdebug + VS Code](#15-xdebug--vs-code)
16. [Testing Strategy](#16-testing-strategy)
17. [Implementation Phases](#17-implementation-phases)
18. [Acceptance Criteria](#18-acceptance-criteria)
19. [How To Use the App](#19-how-to-use-the-app)
20. [Decisions Log](#20-decisions-log)
21. [Open Items To Confirm](#21-open-items-to-confirm)
22. [Appendix: ASCII Diagrams](#22-appendix-ascii-diagrams)

---

## 1. Purpose & Non-Goals

### 1.1 Purpose
Build a small, self-contained PHP 8.5 web application that implements a real (tiny) Transformer language model from the paper "Attention Is All You Need". The app must:

- Let a user paste a small text corpus.
- Tokenize the text at character level and persist a vocabulary.
- Create a tiny Transformer model with editable hyperparameters.
- Train the model via a Symfony Messenger worker using the Doctrine (MariaDB) transport.
- Persist every weight of the model as rows in MariaDB.
- Predict the next characters of a prompt via a Messenger worker.
- Be step-debuggable with Xdebug 3.4 from inside Docker, both for HTTP requests and for the long-running worker.
- Be 100 % covered by PHPUnit tests, with finite-difference gradient checks for the math.

### 1.2 Non-Goals
- No external LLM, no Python, no ONNX, no llama.cpp, no `rubenvb/python-transformers`.
- No GPU, no parallel training, no mini-batching.
- No production-grade performance. We optimize for clarity.
- No multi-user authentication, no API, no SPA frontend. Server-rendered Twig only.
- No React / Vue / Live Component. Plain HTML forms + small inline JS for polling.

### 1.3 Priority Order
1. Clarity (readable in one sitting).
2. Correctness (gradients actually move the loss down).
3. Testability (every line of code has a test).
4. Performance (last).

---

## 2. Tech Stack

| Component | Version | Why |
|---|---|---|
| PHP | 8.5.0+ | Required by user. New pipe operator, clone-with, URI extension. |
| Symfony | 8.1.* | Current stable, declares `php: ^8.4` which admits 8.5. |
| Apache | `httpd:2.4` (via `php:8.5-apache`) | mod_php, zero-config. |
| MariaDB | 11.4 | Required by user. utf8mb4 default. |
| Doctrine ORM | ^3.4 | Standard with Symfony 8.1. |
| Doctrine Migrations | ^3.4 | |
| Symfony Messenger | (bundled with Symfony 8.1) | DB transport included. |
| Twig | ^3.10 | |
| Symfony Forms | (bundled) | |
| Xdebug | 3.4.* | Step debugging. |
| Composer | 2.8 | |
| PHPUnit | 11.5+ | Tests. |
| PHPStan | ^2.0 | Static analysis. |
| Infection | ^0.29 | Optional mutation testing. |
| dama/doctrine-test-bundle | ^8 | Transactional test isolation. |
| zenstruck/foundry | ^2 | Test factories. |
| symfony/test-pack | (dev) | WebTestCase, BrowserKit. |
| pcov | latest | Coverage driver (no conflict with Xdebug). |
| Tailwind (CDN) | 3.x | No build step. |

**Composer bootstrap command:**
```bash
composer create-project symfony/skeleton:^8.1 .
composer require \
  symfony/orm-pack \
  symfony/messenger \
  symfony/twig-bundle \
  symfony/form \
  symfony/validator \
  symfony/asset \
  symfony/uid
composer require --dev \
  symfony/maker-bundle:^1.60 \
  symfony/test-pack \
  phpunit/phpunit:^11.5 \
  dama/doctrine-test-bundle \
  zenstruck/foundry \
  phpstan/phpstan:^2.0 \
  phpstan/phpstan-symfony:^2.0 \
  phpstan/phpstan-doctrine:^2.0
```

---

## 3. Architecture Overview

```
+----------------+        +-----------------+        +-------------------+
|   UI / HTTP    |  --->  |  Application    |  --->  |     Domain        |
|  (Twig, Forms) |        | (CQRS handlers) |        |  (pure PHP, ARs,  |
+----------------+        +-----------------+        |   VOs, Events)    |
        |                          |                 +-------------------+
        v                          v                          ^
+----------------+        +-----------------+                 |
| Infrastructure |  --->  |   Ports /       |  ---------------+
|  (Doctrine,    |        |   Interfaces    |        implemented by
|   Messenger,   |        |   (in Domain)   |        Infrastructure
|   Transformer) |        +-----------------+
+----------------+
```

Three rules:
1. **Domain depends on nothing.** No Symfony, no Doctrine, no PHP annotations referencing framework classes.
2. **Application depends only on Domain.** Handlers depend on `Repository` interfaces and `Port` interfaces defined in Domain.
3. **Infrastructure depends on Application and Domain.** Implements the ports, hosts the math, hosts the Messenger handlers, hosts the Doctrine repositories.

---

## 4. Bounded Contexts

We ship **one** bounded context: `LanguageModel`. It contains four sub-domains:

| Sub-domain | Responsibility |
|---|---|
| `Token` | Tokenization (char level), reserved tokens, vocabulary. |
| `Corpus` | Raw text storage, char statistics. |
| `Model` | `LanguageModel` aggregate, configuration, weights. |
| `Training` | Training jobs, loss history, epoch lifecycle. |
| `Inference` | Predictions, sampling strategies. |

A `Shared` kernel at `src/Shared` provides:
- `AggregateRoot` base class.
- `DomainEvent` interface.
- `Clock` port + `SystemClock` / `MockClock` adapters.

---

## 5. Hexagonal Layers

The directory structure encodes the layers. This is the most important file of the project — copy it verbatim.

```
src/
├── Kernel.php
├── Shared/
│   ├── Domain/
│   │   ├── AggregateRoot.php
│   │   ├── DomainEvent.php
│   │   └── Clock.php                    # interface
│   └── Infrastructure/
│       ├── Clock/SystemClock.php
│       └── Clock/MockClock.php
└── LanguageModel/
    ├── Domain/                          # pure PHP, no framework imports
    │   ├── Model/
    │   │   ├── LanguageModel.php        # AR
    │   │   ├── ModelConfig.php          # VO
    │   │   ├── ModelId.php              # VO
    │   │   ├── ModelStatus.php          # enum: Draft, Ready, Training, Trained, Failed
    │   │   └── Weights.php              # VO; array<float> matrices
    │   ├── Token/
    │   │   ├── TokenId.php              # VO (int 0..vocabSize-1)
    │   │   ├── Character.php            # VO (unicode codepoint)
    │   │   ├── Vocabulary.php           # AR
    │   │   ├── Tokenizer.php            # interface
    │   │   └── TokenSequence.php        # VO (array<TokenId>)
    │   ├── Corpus/
    │   │   ├── Corpus.php               # AR
    │   │   └── CorpusId.php
    │   ├── Training/
    │   │   ├── TrainingJob.php          # AR
    │   │   ├── TrainingConfig.php       # VO
    │   │   └── TrainingLoss.php         # VO
    │   ├── Inference/
    │   │   ├── Prediction.php           # AR
    │   │   ├── PredictionId.php
    │   │   ├── SamplingStrategy.php     # enum: Greedy, TopK
    │   │   └── SamplingConfig.php       # VO
    │   ├── Event/
    │   │   ├── TextIngested.php
    │   │   ├── ModelCreated.php
    │   │   ├── ModelTrained.php
    │   │   ├── EpochCompleted.php
    │   │   └── PredictionGenerated.php
    │   └── Repository/                  # interfaces only
    │       ├── LanguageModelRepository.php
    │       ├── CorpusRepository.php
    │       ├── VocabularyRepository.php
    │       ├── TrainingJobRepository.php
    │       └── PredictionRepository.php
    ├── Application/                     # use-cases; depends on Domain only
    │   ├── Command/
    │   │   ├── IngestTextCommand.php
    │   │   ├── CreateModelCommand.php
    │   │   ├── TrainModelCommand.php
    │   │   └── GeneratePredictionCommand.php
    │   ├── CommandHandler/
    │   │   ├── IngestTextHandler.php
    │   │   ├── CreateModelHandler.php
    │   │   ├── TrainModelHandler.php
    │   │   └── GeneratePredictionHandler.php
    │   ├── Query/
    │   │   ├── ListModelsQuery.php
    │   │   ├── GetModelQuery.php
    │   │   ├── GetVocabQuery.php
    │   │   ├── GetTrainingHistoryQuery.php
    │   │   └── GetPredictionQuery.php
    │   ├── QueryHandler/
    │   │   ├── ListModelsHandler.php
    │   │   ├── GetModelHandler.php
    │   │   ├── GetVocabHandler.php
    │   │   ├── GetTrainingHistoryHandler.php
    │   │   └── GetPredictionHandler.php
    │   └── Port/                        # interfaces for infra
    │       ├── TokenizerPort.php
    │       ├── TrainerPort.php
    │       └── PredictorPort.php
    ├── Infrastructure/                  # Symfony, Doctrine, Messenger
    │   ├── Persistence/Doctrine/
    │   │   ├── Mapping/                 # XML mappings
    │   │   │   ├── LanguageModel.orm.xml
    │   │   │   ├── ModelTokenEmbedding.orm.xml
    │   │   │   ├── ModelPositionalEmbedding.orm.xml
    │   │   │   ├── ModelAttentionWeight.orm.xml
    │   │   │   ├── ModelFfnWeight.orm.xml
    │   │   │   ├── ModelFinalProjection.orm.xml
    │   │   │   ├── Corpus.orm.xml
    │   │   │   ├── Vocabulary.orm.xml
    │   │   │   ├── TrainingJob.orm.xml
    │   │   │   ├── TrainingLossHistory.orm.xml
    │   │   │   └── Prediction.orm.xml
    │   │   └── Repository/
    │   │       ├── DoctrineLanguageModelRepository.php
    │   │       ├── DoctrineCorpusRepository.php
    │   │       ├── DoctrineVocabularyRepository.php
    │   │       ├── DoctrineTrainingJobRepository.php
    │   │       └── DoctrinePredictionRepository.php
    │   ├── Tokenizer/
    │   │   └── CharacterTokenizer.php
    │   ├── Transformer/                 # the math; pure PHP
    │   │   ├── Tensor.php
    │   │   ├── EmbeddingLayer.php
    │   │   ├── AttentionLayer.php
    │   │   ├── FeedForwardLayer.php
    │   │   ├── LayerNorm.php
    │   │   ├── SoftmaxCrossEntropy.php
    │   │   ├── Adam.php
    │   │   ├── ModelTrainer.php
    │   │   └── ModelPredictor.php
    │   └── Messenger/
    │       ├── Message/
    │       │   ├── TrainModelMessage.php
    │       │   └── GeneratePredictionMessage.php
    │       └── Handler/
    │           ├── TrainModelMessageHandler.php
    │           └── GeneratePredictionMessageHandler.php
    └── HttpInterface/                   # UI layer
        ├── Controller/
        │   ├── DashboardController.php
        │   ├── CorpusController.php
        │   ├── ModelController.php
        │   └── PredictionController.php
        ├── Form/
        │   ├── IngestTextType.php
        │   ├── CreateModelType.php
        │   └── GeneratePredictionType.php
        ├── View/                        # read models
        │   ├── ModelView.php
        │   ├── TrainingHistoryView.php
        │   └── PredictionView.php
        └── Twig/
            ├── dashboard.html.twig
            ├── corpus/new.html.twig
            ├── model/list.html.twig
            ├── model/new.html.twig
            ├── model/detail.html.twig
            ├── prediction/new.html.twig
            └── prediction/show.html.twig
```

### Why `Infrastructure/Transformer` is in Infrastructure
The math is pure (no I/O, no framework). It could live in Domain. We put it in Infrastructure to make the teaching read top-down: open `ModelTrainer.php` and you see the whole algorithm in one file. Moving it later is mechanical (just change the namespace; the unit tests do not need to change).

---

## 6. Repository Layout (full tree)

```
.
├── docker/
│   ├── apache/
│   │   └── 000-default.conf
│   ├── php/
│   │   ├── Dockerfile
│   │   ├── php.ini
│   │   └── xdebug.ini
│   └── mariadb/
│       └── conf.d/50-character-set.cnf
├── .vscode/
│   ├── launch.json
│   └── settings.json
├── src/                                  # see section 5
├── tests/                                # see section 16.3
├── templates/
│   └── base.html.twig
├── public/
│   └── index.php
├── var/                                  # gitignored
├── vendor/                               # gitignored
├── config/
│   ├── bundles.php
│   ├── routes.yaml
│   ├── services.yaml
│   ├── preload.php
│   └── packages/
│       ├── doctrine.yaml
│       ├── doctrine_migrations.yaml
│       ├── messenger.yaml
│       ├── framework.yaml
│       ├── twig.yaml
│       ├── monolog.yaml
│       ├── security.yaml                 # empty stub
│       └── test/
│           ├── dama_doctrine_test_bundle.yaml
│           └── framework.yaml
├── migrations/
│   └── Version20260101000001.php
├── bin/console
├── Makefile
├── composer.json
├── composer.lock
├── phpunit.xml.dist
├── phpstan.neon
├── infection.json
├── .env
├── .env.local.example
├── .env.test
├── .gitignore
├── docker-compose.yml
├── SPECKIT.md                            # this file
└── README.md
```

---

## 7. Domain Model

### 7.1 Aggregates

#### `Corpus`
- Fields: `CorpusId $id`, `string $name`, `string $rawText`, `\DateTimeImmutable $createdAt`.
- Methods: `static create(string $name, string $text, Clock $clock): self`, `appendChunk(string $chunk): void`, `length(): int`, `uniqueCharacters(): array<Character>`.
- Events: raises `TextIngested` on first create and on each `appendChunk`.

#### `Vocabulary`
- Fields: `CorpusId $corpusId`, `array<int, Character> $idToChar`, `array<string, int> $charToId`, `int $nextId`.
- Reserved ids: `0 = <pad>`, `1 = <bos>`, `2 = <unk>`. These are always present, immutable.
- Methods: `static empty(CorpusId): self`, `encode(string): TokenSequence`, `decode(TokenSequence): string`, `addCharacter(Character): TokenId`, `size(): int`, `contains(Character): bool`.
- Invariants:
  - `nextId` starts at 3 and only grows.
  - `encode` maps unknown characters to `<unk>` (`TokenId(2)`).
  - `decode` of `<pad>` produces empty string.
- Events: raises `VocabularyExtended` (internal event, not needed for application code; documented for completeness).

#### `LanguageModel`
- Fields: `ModelId $id`, `string $name`, `ModelConfig $config`, `ModelStatus $status`, `?Weights $weights`, `\DateTimeImmutable $createdAt`, `\DateTimeImmutable $updatedAt`.
- Methods:
  - `static create(string $name, ModelConfig, Clock): self` → status `Draft`, random init of weights (delegated to `TrainerPort::initializeWeights`).
  - `markReady(): void` → `Draft → Ready`.
  - `startTraining(): void` → `Ready → Training`.
  - `applyGradient(Weights $newWeights): void` → `Training → Training`; only valid in `Training` state.
  - `markTrained(): void` → `Training → Trained` (only after `totalEpochs` epochs applied — handler enforces).
  - `markFailed(string $reason): void` → any → `Failed`.
- State machine (enforced by methods above):
  ```
  Draft --markReady--> Ready
  Ready --startTraining--> Training
  Training --applyGradient--> Training
  Training --markTrained--> Trained
  {Draft, Ready, Training, Trained} --markFailed--> Failed
  ```

#### `TrainingJob`
- Fields: `TrainingJobId $id`, `ModelId $modelId`, `TrainingConfig $config`, `TrainingStatus $status` (enum: `Queued, Running, Done, Failed`), `int $epoch`, `?TrainingLoss $lastLoss`, `\DateTimeImmutable $createdAt`, `?\DateTimeImmutable $startedAt`, `?\DateTimeImmutable $finishedAt`, `?string $errorMessage`.
- Methods: `static queue(ModelId, TrainingConfig, Clock): self`, `start(): void`, `recordEpoch(int $epoch, TrainingLoss): void`, `complete(): void`, `fail(string $reason, Clock): void`.
- Invariants: `epoch` starts at 0, increments by 1 per `recordEpoch`, never exceeds `config.totalEpochs`.

#### `Prediction`
- Fields: `PredictionId $id`, `ModelId $modelId`, `string $prompt`, `?string $generatedText`, `SamplingConfig $sampling`, `PredictionStatus $status` (enum: `Queued, Running, Done, Failed`), `\DateTimeImmutable $createdAt`, `?\DateTimeImmutable $finishedAt`, `?string $errorMessage`.
- Methods: `static queue(ModelId, string $prompt, SamplingConfig, Clock): self`, `complete(string $generatedText, Clock): void`, `fail(string, Clock): void`.

### 7.2 Value Objects

| VO | Type | Notes |
|---|---|---|
| `ModelConfig` | readonly class | `dModel`, `numHeads`, `numLayers`, `dFf`, `maxSeqLen`, `vocabSize`. Validates `dModel % numHeads == 0`, all positive. |
| `Weights` | readonly class | Holds the matrices as `array<string, array<int, array<int, float>>>`. Keys: `tokenEmbed`, `posEmbed`, `attn[layer].{wq,wk,wv,wo}`, `ffn[layer].{w1,b1,w2,b2}`, `final`. Helper methods: `get(string $path): array`, `withUpdate(string $path, array $newValues): self`. |
| `TokenId` | readonly class | Wraps `int` 0..vocabSize-1. |
| `Character` | readonly class | Wraps a single unicode codepoint (stored as the codepoint int, not a string — avoids grapheme bugs). |
| `TokenSequence` | readonly class | `array<TokenId>`. Method `length()`, `window(int $start, int $end): self`, `shift(): TokenId`. |
| `TrainingConfig` | readonly class | `learningRate`, `totalEpochs`, `seqLen`, `batchSize=1`. |
| `TrainingLoss` | readonly class | `float $value`. |
| `SamplingConfig` | readonly class | `SamplingStrategy $strategy`, `int $maxNewTokens`, `?int $topK` (only when `TopK`). |
| `ModelId, CorpusId, TrainingJobId, PredictionId` | readonly class | Uuid v7 (uses `symfony/uid`). |

### 7.3 Domain Events
Plain readonly classes implementing `DomainEvent`:
- `TextIngested { CorpusId, int $length }`
- `ModelCreated { ModelId, ModelConfig }`
- `ModelTrained { ModelId, int $totalEpochs, float $finalLoss }`
- `EpochCompleted { ModelId, int $epoch, float $loss }`
- `PredictionGenerated { PredictionId, string $generatedText }`

Events are collected in the `AR` and dispatched by `EventRecordingMiddleware` on the command bus.

### 7.4 Repository Interfaces (Domain layer, signature only)
```php
interface LanguageModelRepository {
    public function save(LanguageModel $m): void;
    public function find(ModelId $id): ?LanguageModel;
    /** @return LanguageModel[] */
    public function all(): array;
    public function saveWeights(ModelId $id, Weights $w): void;
    public function loadWeights(ModelId $id): Weights;
}
interface CorpusRepository { save(Corpus): void; find(CorpusId): ?Corpus; all(): array; }
interface VocabularyRepository { save(Vocabulary): void; findByCorpus(CorpusId): ?Vocabulary; }
interface TrainingJobRepository {
    save(TrainingJob): void;
    find(TrainingJobId): ?TrainingJob;
    findByModel(ModelId): array;
    recordEpoch(TrainingJobId, int $epoch, TrainingLoss): void;
}
interface PredictionRepository {
    save(Prediction): void;
    find(PredictionId): ?Prediction;
    findByModel(ModelId, int $limit=20): array;
}
```

---

## 8. Application Layer (CQRS)

### 8.1 Two message buses

`config/packages/messenger.yaml` defines:
- `command.bus` — middleware: `doctrine_transaction`, `dispatch_after_current_bus`, `event_recording`.
- `query.bus` — middleware: none (sync, in-process).

### 8.2 Commands

| Command | Handler | What it does | Returns |
|---|---|---|---|
| `IngestTextCommand { name, text }` | `IngestTextHandler` | Creates `Corpus`, builds/extends `Vocabulary`, emits `TextIngested`. | `CorpusId` |
| `CreateModelCommand { name, ModelConfig, TrainingConfig }` | `CreateModelHandler` | Creates `LanguageModel` (random init via `TrainerPort::initializeWeights`), persists. | `ModelId` |
| `TrainModelCommand { ModelId, TrainingConfig }` | `TrainModelHandler` | Validates model is `Ready`, creates `TrainingJob`, dispatches `TrainModelMessage` to `async_training`. | `TrainingJobId` |
| `GeneratePredictionCommand { ModelId, prompt, SamplingConfig }` | `GeneratePredictionHandler` | Validates model is `Trained` or `Ready`, creates `Prediction`, dispatches `GeneratePredictionMessage` to `async_inference`. | `PredictionId` |

### 8.3 Queries

| Query | Handler | Returns |
|---|---|---|
| `ListModelsQuery` | `ListModelsHandler` | `array<ModelView>` |
| `GetModelQuery { ModelId }` | `GetModelHandler` | `?ModelView` (with config, status, weight summary) |
| `GetVocabQuery { CorpusId }` | `GetVocabHandler` | `array{id: int, char: string}[]` |
| `GetTrainingHistoryQuery { ModelId }` | `GetTrainingHistoryHandler` | `TrainingHistoryView` (loss series, status) |
| `GetPredictionQuery { PredictionId }` | `GetPredictionHandler` | `?PredictionView` |

### 8.4 Handlers — testing rules
- Handlers depend only on the port interfaces and the message bus interface.
- They never call `new \DateTimeImmutable()`; they receive `Clock` via constructor.
- Each handler has ≥ 4 tests: happy path, missing entity, invalid state transition, dispatcher called with expected message.

### 8.5 Application Ports

```php
interface TokenizerPort {
    public function buildVocabulary(CorpusId, string $text): Vocabulary;
    public function tokenize(Vocabulary, string): TokenSequence;
    public function detokenize(Vocabulary, TokenSequence): string;
}
interface TrainerPort {
    public function initializeWeights(ModelConfig): Weights;
    public function trainOneEpoch(LanguageModel $m, TokenSequence $data, TrainingConfig $c): Weights;
}
interface PredictorPort {
    public function generate(LanguageModel $m, TokenSequence $prompt, SamplingConfig $s): TokenSequence;
}
```

`CharacterTokenizer` implements `TokenizerPort`. `ModelTrainer` implements `TrainerPort`. `ModelPredictor` implements `PredictorPort`. Wired in `config/services.yaml`.

---

## 9. Infrastructure Layer

### 9.1 Doctrine Repositories
One class per interface, lives in `Infrastructure/Persistence/Doctrine/Repository/`. Each:
- Receives `EntityManagerInterface` via constructor.
- Maps between the AR/VO and the Doctrine entity (entities live in `Infrastructure/Persistence/Doctrine/Entity/`).
- Uses `EntityManager::wrapInTransaction` for `saveWeights` to be atomic.

The `LanguageModel` AR is split into multiple Doctrine entities to keep tables normalized:
- `LanguageModelEntity` — header row.
- `ModelTokenEmbeddingEntity` — one row per `(modelId, tokenId, dim)`.
- `ModelPositionalEmbeddingEntity` — one row per `(modelId, position, dim)`.
- `ModelAttentionWeightEntity` — one row per `(modelId, layer, matrix, row, col)`.
- `ModelFfnWeightEntity` — one row per `(modelId, layer, matrix, row, col)`.
- `ModelFinalProjectionEntity` — one row per `(modelId, row, col)`.

The repository hydrates the full `LanguageModel` AR (with its `Weights` VO) on `loadWeights`. Loading a model with weights is one method: `findWithWeights`.

### 9.2 Tokenizer
`CharacterTokenizer`:
- Walks the input string codepoint by codepoint (`mb_chr` / `mb_ord`).
- For each new codepoint, adds it to the vocabulary starting at id 3 (after reserved).
- Reserved tokens `<pad>,<bos>,<unk>` are characters `"\x00", "\x01", "\x02"`.
- On encode, returns a `TokenSequence` (array of `TokenId`).
- On decode, joins the characters; unknown ids become `?`.

### 9.3 Transformer Math
See section 12 for full detail. Each class lives under `src/LanguageModel/Infrastructure/Transformer/`. All classes:
- Are deterministic given a seed.
- Store forward activations as a private array on the layer object so backward is one call.
- Have a `@phpstan-ignore` budget of **zero** in production code; math must be provable with PHPStan.

### 9.4 Messenger Handlers

#### `TrainModelMessageHandler`
1. Loads `TrainingJob` and `LanguageModel` (with weights).
2. Loads the corpus and the vocabulary.
3. Tokenizes the corpus once (cached in `application_cache` directory or in `training_jobs.cache_token_sequence` as a JSON column).
4. For each epoch in a small chunk (default 1 epoch per handler invocation):
   - `trainer->trainOneEpoch(...)` → new `Weights`.
   - Persist weights, record `EpochCompleted`, advance `epoch`.
5. If `epoch < totalEpochs`, re-dispatch `TrainModelMessage` (so the worker can be killed between epochs — important for step debugging).
6. If `epoch == totalEpochs`, mark job `Done`, model `Trained`, emit `ModelTrained`.

The `--limit=1` flag on `messenger:consume` therefore equals "one epoch at a time".

#### `GeneratePredictionMessageHandler`
1. Loads `Prediction` and `LanguageModel` (with weights).
2. Encodes prompt via `TokenizerPort`.
3. `predictor->generate(...)` returns a `TokenSequence`.
4. `detokenize` → string.
5. `Prediction::complete($text, $clock)`. Emit `PredictionGenerated`.

---

## 10. Database Schema (MariaDB)

All tables use `utf8mb4` / `utf8mb4_unicode_ci`. All ids are `BIGINT AUTO_INCREMENT PRIMARY KEY`. All timestamps are `DATETIME(6)`.

```sql
CREATE TABLE corpora (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  raw_text LONGTEXT NOT NULL,
  created_at DATETIME(6) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE vocabulary (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  corpus_id BIGINT NOT NULL,
  token_id INT NOT NULL,
  character VARBINARY(8) NOT NULL,
  UNIQUE KEY uq_vocab_corpus_token (corpus_id, token_id),
  UNIQUE KEY uq_vocab_corpus_char (corpus_id, character),
  CONSTRAINT fk_vocab_corpus FOREIGN KEY (corpus_id) REFERENCES corpora (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE language_models (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  d_model SMALLINT UNSIGNED NOT NULL,
  num_heads SMALLINT UNSIGNED NOT NULL,
  num_layers SMALLINT UNSIGNED NOT NULL,
  d_ff SMALLINT UNSIGNED NOT NULL,
  max_seq_len SMALLINT UNSIGNED NOT NULL,
  vocab_size SMALLINT UNSIGNED NOT NULL,
  status ENUM('draft','ready','training','trained','failed') NOT NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE model_token_embeddings (
  model_id BIGINT NOT NULL,
  token_id INT NOT NULL,
  dim SMALLINT UNSIGNED NOT NULL,
  value FLOAT NOT NULL,
  PRIMARY KEY (model_id, token_id, dim),
  CONSTRAINT fk_mte_model FOREIGN KEY (model_id) REFERENCES language_models (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE model_positional_embeddings (
  model_id BIGINT NOT NULL,
  position SMALLINT UNSIGNED NOT NULL,
  dim SMALLINT UNSIGNED NOT NULL,
  value FLOAT NOT NULL,
  PRIMARY KEY (model_id, position, dim),
  CONSTRAINT fk_mpe_model FOREIGN KEY (model_id) REFERENCES language_models (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE model_attention_weights (
  model_id BIGINT NOT NULL,
  layer SMALLINT UNSIGNED NOT NULL,
  matrix ENUM('wq','wk','wv','wo') NOT NULL,
  row SMALLINT UNSIGNED NOT NULL,
  col SMALLINT UNSIGNED NOT NULL,
  value FLOAT NOT NULL,
  PRIMARY KEY (model_id, layer, matrix, row, col),
  CONSTRAINT fk_maw_model FOREIGN KEY (model_id) REFERENCES language_models (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE model_ffn_weights (
  model_id BIGINT NOT NULL,
  layer SMALLINT UNSIGNED NOT NULL,
  matrix ENUM('w1','b1','w2','b2') NOT NULL,
  row SMALLINT UNSIGNED NOT NULL,
  col SMALLINT UNSIGNED NOT NULL,
  value FLOAT NOT NULL,
  PRIMARY KEY (model_id, layer, matrix, row, col),
  CONSTRAINT fk_mfw_model FOREIGN KEY (model_id) REFERENCES language_models (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE model_final_projection (
  model_id BIGINT NOT NULL,
  row SMALLINT UNSIGNED NOT NULL,
  col SMALLINT UNSIGNED NOT NULL,
  value FLOAT NOT NULL,
  PRIMARY KEY (model_id, row, col),
  CONSTRAINT fk_mfp_model FOREIGN KEY (model_id) REFERENCES language_models (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE training_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  model_id BIGINT NOT NULL,
  status ENUM('queued','running','done','failed') NOT NULL,
  total_epochs INT UNSIGNED NOT NULL,
  learning_rate FLOAT NOT NULL,
  started_at DATETIME(6) NULL,
  finished_at DATETIME(6) NULL,
  loss FLOAT NULL,
  error_message TEXT NULL,
  created_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_tj_model FOREIGN KEY (model_id) REFERENCES language_models (id)
) ENGINE=InnoDB;

CREATE TABLE training_loss_history (
  training_job_id BIGINT NOT NULL,
  epoch INT UNSIGNED NOT NULL,
  loss FLOAT NOT NULL,
  PRIMARY KEY (training_job_id, epoch),
  CONSTRAINT fk_tlh_job FOREIGN KEY (training_job_id) REFERENCES training_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE predictions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  model_id BIGINT NOT NULL,
  prompt VARCHAR(500) NOT NULL,
  generated_text VARCHAR(500) NULL,
  sampling ENUM('greedy','top_k') NOT NULL,
  top_k SMALLINT UNSIGNED NULL,
  status ENUM('queued','running','done','failed') NOT NULL,
  error_message TEXT NULL,
  created_at DATETIME(6) NOT NULL,
  finished_at DATETIME(6) NULL,
  CONSTRAINT fk_pred_model FOREIGN KEY (model_id) REFERENCES language_models (id)
) ENGINE=InnoDB;

-- Created by Symfony Messenger
CREATE TABLE messenger_messages (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  body LONGTEXT NOT NULL,
  headers LONGTEXT NOT NULL,
  queue_name VARCHAR(190) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  available_at DATETIME(6) NOT NULL,
  delivered_at DATETIME(6) NULL
) ENGINE=InnoDB;

CREATE TABLE messenger_failed_messages (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  transport VARCHAR(190) NOT NULL,
  body LONGTEXT NOT NULL,
  headers LONGTEXT NOT NULL,
  queue_name VARCHAR(190) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  available_at DATETIME(6) NOT NULL,
  delivered_at DATETIME(6) NULL,
  failed_at DATETIME(6) NULL,
  error LONGTEXT NULL,
  error_count INT NOT NULL
) ENGINE=InnoDB;
```

### 10.1 Storage size sanity check
For default `dModel=8, numHeads=1, numLayers=1, dFf=16, maxSeqLen=32, vocabSize=64`:
- `model_token_embeddings`: 64 × 8 = 512 rows
- `model_positional_embeddings`: 32 × 8 = 256 rows
- `model_attention_weights`: 4 matrices × 8 × 8 = 256 rows
- `model_ffn_weights`: (8×16+16) + (16×8+8) = 144 + 136 = 280 rows
- `model_final_projection`: 64 × 8 = 512 rows
- **Total: 1 816 rows per model.** Trivial to inspect with raw SQL.

### 10.2 Why one row per matrix element
- The user can run `SELECT * FROM model_attention_weights WHERE model_id = 1 AND layer = 0 AND matrix = 'wq' ORDER BY row, col` and see a real matrix.
- The user can reset a layer with `DELETE FROM model_attention_weights WHERE model_id=? AND layer=?`.
- Step-debugging can pause between rows, since each row is its own Doctrine entity.

---

## 11. Messenger Setup

### 11.1 `config/packages/messenger.yaml`
```yaml
framework:
  messenger:
    failure_transport: failed

    default_bus: command.bus

    buses:
      command.bus:
        middleware:
          - doctrine_transaction
          - dispatch_after_current_bus
          - App\Shared\Infrastructure\Messenger\EventRecordingMiddleware
      query.bus: ~

    transports:
      async_training:
        dsn: 'doctrine://default?queue_name=training'
        options:
          auto_setup: true
        retry_strategy:
          max_retries: 3
          delay: 1000
          multiplier: 2
          max_delay: 10000

      async_inference:
        dsn: 'doctrine://default?queue_name=inference'
        options:
          auto_setup: true
        retry_strategy:
          max_retries: 2
          delay: 500
          multiplier: 2
          max_delay: 5000

      failed:
        dsn: 'doctrine://default?queue_name=failed'
        options:
          auto_setup: true

    routing:
      App\LanguageModel\Infrastructure\Messenger\Message\TrainModelMessage: async_training
      App\LanguageModel\Infrastructure\Messenger\Message\GeneratePredictionMessage: async_inference
```

### 11.2 Workers
Two long-running processes (one terminal tab each):
```bash
docker compose exec php php bin/console messenger:consume async_training -vv
docker compose exec php php bin/console messenger:consume async_inference -vv
```

For step-debugging, run with `--limit=1` to process one epoch (training) or one prediction (inference) and exit, so the developer can inspect state between runs:
```bash
docker compose exec php php -d xdebug.start_with_request=yes bin/console messenger:consume async_training -vv --limit=1
```

### 11.3 Re-dispatch pattern for resumable training
`TrainModelMessageHandler` re-dispatches `TrainModelMessage` after each epoch, so killing the worker mid-epoch is safe — the next `TrainModelCommand` (or a re-dispatched message in the queue) continues from the persisted weights.

---

## 12. Transformer Math (no external library)

### 12.1 Default hyperparameters
Stored in `TrainingConfig` and `ModelConfig`. Defaults:
- `dModel = 8`
- `numHeads = 1` (must divide `dModel`)
- `numLayers = 1`
- `dFf = 16` (= 2 × `dModel`, per the paper)
- `maxSeqLen = 32`
- `vocabSize = ~64` (depends on the corpus)
- `learningRate = 0.005`
- `totalEpochs = 50` (user-configurable)
- `seqLen = 32`
- `batchSize = 1`

### 12.2 Architecture diagram (per layer)
```
x ─► Embedding(tok) ─┐
                     ├─► + ─► LayerNorm ─► Attention ─► + ─► LayerNorm ─► FFN ─► +
x ─► Embedding(pos) ─┘
```
Pre-norm formulation (chosen for simpler gradient flow). Residual connections are standard.

### 12.3 Forward pass (per `ModelTrainer::trainOneStep`)
For input token ids `x[0..T-1]`, target = `x[1..T]`:
1. `tok = Embedding(x)` → `(T, dModel)`.
2. `pos = Embedding([0..T-1])` → `(T, dModel)`.
3. `h = tok + pos`.
4. For each layer:
   - `h = h + Attention(LayerNorm(h))`  — multi-head, single head for default config.
   - `h = h + FFN(LayerNorm(h))`        — `FFN(x) = ReLU(x @ W1 + b1) @ W2 + b2`.
5. `logits = h @ W_final` → `(T, vocabSize)`.
6. `loss = softmax_cross_entropy(logits, target)`.

### 12.4 Inference
1. Encode prompt via `TokenizerPort`.
2. Loop up to `maxNewTokens`:
   - Forward pass.
   - Sample next token from the last position's logits using `SamplingStrategy`.
   - If sampled id is `<pad>` or `<bos>` → stop.
   - Append sampled id to running sequence.
3. Decode the extended sequence (prompt + generated ids).
4. Return the generated suffix.

### 12.5 Backward pass (hand-derived)

Each layer exposes two methods:
- `forward(Tensor $input): Tensor`
- `backward(Tensor $dOut): Tensor` — returns the gradient with respect to input and stores parameter gradients in a `$grads` array on the layer.

We implement the following standard backward formulas:

| Layer | Forward | Backward (returns $dInput, stores $grads) |
|---|---|---|
| `Embedding` | `output[tokenId] = W[tokenId]` | `dW[tokenId] += scatter(dOut[tokenId])` |
| `LayerNorm` | `y = (x-μ)/σ · γ + β` | Standard analytical grad for γ, β, x. |
| `Attention` (single-head, causal) | `Q=X·Wq, K=X·Wk, V=X·Wv; A=softmax(mask(Q·Kᵀ/√d)+mask); Y=A·V; O=Y·Wo` | Backprop through softmax, mask, matmul. Store `dWq,dWk,dWv,dWo`. |
| `FFN` | `h1=ReLU(x·W1+b1); y=h1·W2+b2` | Backprop through ReLU + 2 matmuls. |
| `SoftmaxCrossEntropy` | `loss = -log(softmax(z)[y])` | `dZ = softmax(z) − onehot(y)` — combined for stability. |

We store forward activations (`Q, K, V, attn, h1, h2, mean, var, ...`) on the layer object during forward so backward is a single call with no recomputation.

### 12.6 Optimizer
`Adam` with `β1=0.9`, `β2=0.999`, `ε=1e-8`. Each parameter has a `(m, v)` state stored in a separate `adam_state` table (one row per `(modelId, path, idx)`); loaded and saved around the step. We do NOT use bias correction (`β1^t, β2^t`) to keep the math obvious — the default `lr=0.005` works fine without it on tiny models.

### 12.7 Persistence
After each `Adam::step`:
1. `EntityManager::wrapInTransaction` begins.
2. `DELETE FROM model_<x>_weights WHERE model_id = ?` for each table.
3. `INSERT` all rows of the new weights.
4. `UPDATE training_loss_history SET ...`.
5. Commit.

This is intentionally simple. The table sizes are < 2 000 rows, the transaction is fast enough to step through, and the user can watch the rows change live in a separate `mysql` client.

### 12.8 File-by-file LOC budget
| File | Approx LOC | Tested by |
|---|---|---|
| `Tensor.php` | 90 | `TensorTest` |
| `EmbeddingLayer.php` | 60 | `EmbeddingLayerTest` |
| `AttentionLayer.php` | 220 | `AttentionLayerTest` (incl. finite-diff) |
| `FeedForwardLayer.php` | 110 | `FeedForwardLayerTest` (incl. finite-diff) |
| `LayerNorm.php` | 110 | `LayerNormTest` (incl. finite-diff) |
| `SoftmaxCrossEntropy.php` | 80 | `SoftmaxCrossEntropyTest` (incl. finite-diff) |
| `Adam.php` | 70 | `AdamTest` |
| `ModelTrainer.php` | 180 | `ModelTrainerTest` (incl. loss-decreases) |
| `ModelPredictor.php` | 90 | `ModelPredictorTest` |
| `CharacterTokenizer.php` | 80 | `CharacterTokenizerTest` |
| **Total** | **~1 090** | **~20 test classes** |

---

## 13. HTTP / UI Layer

### 13.1 Routes

| Method | Path | Controller | Purpose |
|---|---|---|---|
| GET | `/` | `DashboardController::index` | Counts + buttons to enqueue workers. |
| GET, POST | `/corpus/new` | `CorpusController::new` | Paste text → `IngestTextCommand`. |
| GET | `/corpus/{id}` | `CorpusController::show` | Show vocab preview. |
| GET | `/model` | `ModelController::list` | Table of all models. |
| GET, POST | `/model/new` | `ModelController::new` | Form for `ModelConfig` + `TrainingConfig`. |
| GET | `/model/{id}` | `ModelController::show` | Config, vocab, loss chart, action buttons. |
| POST | `/model/{id}/train-one-epoch` | `ModelController::trainOneEpoch` | Dispatch `TrainModelCommand` for 1 epoch. |
| POST | `/model/{id}/train-n-epochs` | `ModelController::trainN` | Dispatch `TrainModelCommand` for N epochs. |
| GET, POST | `/prediction/new?model={id}` | `PredictionController::new` | Form for prompt + sampling. |
| GET | `/prediction/{id}` | `PredictionController::show` | Result page; auto-refreshes every 1s while `status != done`. |

### 13.2 Templates
- `base.html.twig` — Tailwind CDN, top nav, flash messages.
- `dashboard.html.twig` — KPI cards.
- `corpus/new.html.twig` — `<textarea name="text">` + name input.
- `model/list.html.twig` — table with name, status, last loss, actions.
- `model/new.html.twig` — form for hyperparameters.
- `model/detail.html.twig` — config, inline SVG loss chart, action buttons, vocab table.
- `prediction/new.html.twig` — prompt textarea, sampling select, max_new_tokens input.
- `prediction/show.html.twig` — prompt, generated text, error message if failed.

### 13.3 Inline SVG loss chart
A small `macro` reads `TrainingHistoryView` and emits a `<svg width=400 height=120>` with a `<polyline>`. ~25 LOC of Twig. No JS.

### 13.4 Polling
`prediction/show.html.twig` includes:
```html
<meta http-equiv="refresh" content="1">
```
while `status != done` (Twig conditional). Zero JS.

---

## 14. Docker Setup

### 14.1 `docker-compose.yml`
```yaml
services:
  php:
    build: ./docker/php
    volumes:
      - ./:/app
      - ./docker/php/xdebug.ini:/usr/local/etc/php/conf.d/xdebug.ini:ro
    ports:
      - "8080:80"
    environment:
      XDEBUG_CONFIG: "client_host=host.docker.internal client_port=9003"
      PHP_IDE_CONFIG: "serverName=transformer-app"
    depends_on:
      mariadb:
        condition: service_healthy
    extra_hosts:
      - "host.docker.internal:host-gateway"

  mariadb:
    image: mariadb:11.4
    environment:
      MARIADB_ROOT_PASSWORD: root
      MARIADB_DATABASE: transformer
      MARIADB_USER: transformer
      MARIADB_PASSWORD: transformer
    ports:
      - "3306:3306"
    volumes:
      - dbdata:/var/lib/mysql
      - ./docker/mariadb/conf.d:/etc/mysql/conf.d:ro
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 20

volumes:
  dbdata:
```

### 14.2 `docker/php/Dockerfile`
```dockerfile
FROM php:8.5-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        default-mysql-client \
        mariadb-client \
    && docker-php-ext-install -j$(nproc) pdo_mysql intl opcache zip \
    && pecl install xdebug-3.4.* \
    && docker-php-ext-enable xdebug \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Tune Apache to /app/public (the php:apache image already points there)
RUN echo "ServerName transformer-app" >> /etc/apache2/apache2.conf
```

### 14.3 `docker/php/php.ini`
```ini
memory_limit = 512M
opcache.enable = 1
opcache.enable_cli = 0
date.timezone = UTC
expose_php = Off
```

### 14.4 `docker/php/xdebug.ini`
```ini
zend_extension=xdebug.so
xdebug.mode=develop,debug
xdebug.start_with_request=trigger
xdebug.trigger_value=VSCODE
xdebug.client_host=host.docker.internal
xdebug.client_port=9003
xdebug.idekey=VSCODE
xdebug.discover_client_host=0
xdebug.log_level=0
xdebug.max_nesting_level=512
```

### 14.5 `docker/apache/000-default.conf`
```apache
<VirtualHost *:80>
    ServerName transformer-app
    DocumentRoot /app/public
    <Directory /app/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 14.6 `docker/mariadb/conf.d/50-character-set.cnf`
```ini
[mysqld]
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
[client]
default-character-set = utf8mb4
```

### 14.7 `.env` / `.env.local.example`
```
APP_ENV=dev
APP_SECRET=changeme
DATABASE_URL="mysql://transformer:transformer@mariadb:3306/transformer?serverVersion=11.4&charset=utf8mb4"
MESSENGER_TRANSPORT_DSN=doctrine://default
```

---

## 15. Xdebug + VS Code

### 15.1 `.vscode/launch.json`
```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "Listen for Xdebug (web)",
      "type": "php",
      "request": "launch",
      "port": 9003,
      "pathMappings": { "/app": "${workspaceFolder}" }
    },
    {
      "name": "Debug current PHP script",
      "type": "php",
      "request": "launch",
      "program": "${file}",
      "cwd": "${workspaceFolder}",
      "pathMappings": { "/app": "${workspaceFolder}" }
    },
    {
      "name": "Debug Messenger worker: training",
      "type": "php",
      "request": "launch",
      "program": "/app/bin/console",
      "args": ["messenger:consume", "async_training", "-vv", "--time-limit=3600"],
      "cwd": "/app",
      "env": {
        "XDEBUG_CONFIG": "client_host=host.docker.internal client_port=9003"
      },
      "pathMappings": { "/app": "${workspaceFolder}" }
    },
    {
      "name": "Debug Messenger worker: inference",
      "type": "php",
      "request": "launch",
      "program": "/app/bin/console",
      "args": ["messenger:consume", "async_inference", "-vv", "--time-limit=3600"],
      "cwd": "/app",
      "env": {
        "XDEBUG_CONFIG": "client_host=host.docker.internal client_port=9003"
      },
      "pathMappings": { "/app": "${workspaceFolder}" }
    }
  ]
}
```

### 15.2 `.vscode/settings.json`
```json
{
  "php.validate.executablePath": "docker compose exec -T php php",
  "php.suggest.basic": false,
  "files.eol": "\n"
}
```

### 15.3 Triggering debug

| Scenario | Action |
|---|---|
| Web request | Install browser extension "Xdebug Helper" → enable. Or visit `?XDEBUG_SESSION=VSCODE`. |
| Worker | Start the "Debug Messenger worker: training" configuration in VS Code. The `XDEBUG_CONFIG` env var forces Xdebug to attach at startup. |
| One-off CLI | `docker compose exec php php -d xdebug.start_with_request=yes bin/console <command>` |

### 15.4 Required PHP extensions for the IDE
- `felixfbecker.php-intellisense` (optional but nice).
- `bmewburn.vscode-intense-phpdark` or any color theme.

---

## 16. Testing Strategy

### 16.1 Core principle
**Every line of code is exercised by a PHPUnit test before it is considered "done".** Tests are written alongside the code, often test-first. The hexagonal layout is what makes this realistic:
- **Domain** is 100 % unit-testable with zero mocks.
- **Application** is 100 % unit-testable with mocked ports.
- **Infrastructure math** is unit-tested with numerical (finite-difference) gradient checks.
- **Infrastructure repositories and Messenger handlers** are integration-tested against a real MariaDB.
- **HTTP controllers** are covered with `WebTestCase`.

### 16.2 PHPUnit setup
- PHPUnit 11.5+ (current stable; supports PHP 8.5 and readonly classes).
- `dama/doctrine-test-bundle` wraps every test in a transaction that rolls back at the end — DB tests are fast and isolated.
- `zenstruck/foundry` provides factories/proxies for entities.
- `symfony/test-pack` brings `WebTestCase` and `KernelTestCase`.
- Coverage via `pcov` for normal runs (fast, no Xdebug conflict); Xdebug only for the `make test-xdebug` smoke.

### 16.3 Test directory layout (mirrors `src/`)
```
tests/
├── Unit/
│   ├── LanguageModel/
│   │   ├── Domain/
│   │   │   ├── Model/{LanguageModelTest, WeightsTest, ModelConfigTest}.php
│   │   │   ├── Token/{VocabularyTest, TokenSequenceTest, TokenIdTest, CharacterTest}.php
│   │   │   ├── Corpus/CorpusTest.php
│   │   │   ├── Training/{TrainingJobTest, TrainingConfigTest, TrainingLossTest}.php
│   │   │   └── Inference/{PredictionTest, SamplingConfigTest}.php
│   │   ├── Application/
│   │   │   ├── CommandHandler/{IngestTextHandlerTest, CreateModelHandlerTest, TrainModelHandlerTest, GeneratePredictionHandlerTest}.php
│   │   │   ├── QueryHandler/{ListModelsHandlerTest, GetModelHandlerTest, GetVocabHandlerTest, GetTrainingHistoryHandlerTest, GetPredictionHandlerTest}.php
│   │   │   └── Port/{TrainerPortStub, PredictorPortStub, TokenizerPortStub}.php
│   │   └── Infrastructure/
│   │       ├── Transformer/{TensorTest, EmbeddingLayerTest, AttentionLayerTest, FeedForwardLayerTest, LayerNormTest, SoftmaxCrossEntropyTest, AdamTest, ModelTrainerTest, ModelPredictorTest}.php
│   │       └── Tokenizer/CharacterTokenizerTest.php
│   └── Shared/
│       └── Domain/{AggregateRootTest, ClockTest}.php
├── Integration/
│   ├── KernelTestCase.php
│   └── LanguageModel/
│       ├── Infrastructure/
│       │   ├── Persistence/Doctrine/{DoctrineLanguageModelRepositoryTest, DoctrineCorpusRepositoryTest, DoctrineVocabularyRepositoryTest, DoctrineTrainingJobRepositoryTest, DoctrinePredictionRepositoryTest}.php
│       │   └── Messenger/{TrainModelMessageHandlerTest, GeneratePredictionMessageHandlerTest}.php
│       └── EndToEnd/TrainAndPredictFlowTest.php
├── Functional/
│   ├── WebTestCase.php
│   └── HttpInterface/
│       ├── DashboardControllerTest.php
│       ├── CorpusControllerTest.php
│       ├── ModelControllerTest.php
│       └── PredictionControllerTest.php
├── Fixtures/
│   ├── CorpusFactory.php
│   ├── LanguageModelFactory.php
│   ├── TrainingJobFactory.php
│   ├── PredictionFactory.php
│   └── sample_corpus.txt
└── bootstrap.php
```

### 16.4 What is tested per layer

| Layer | What is tested | How |
|---|---|---|
| **Domain VOs & ARs** | Invariants, mutators, raised events, equality, immutability, edge cases (empty, zero vocab, single char). | Plain PHPUnit, zero mocks. |
| **Application handlers** | Happy path; every error branch; state machine guard violations; correct message dispatched. | Mocked `Repository`, `MessageBusInterface`, ports, `Clock`. |
| **Application queries** | Returned DTO shape; correct repository called. | Mocked repository. |
| **Transformer math** | Shape checks; finite-difference gradient checks for every parameter; numerical stability; seeded determinism; edge cases (mask, empty). | Plain PHPUnit, in-memory. |
| **Tokenizer** | Round-trip; unknown chars → `<unk>`; reserved tokens. | Plain PHPUnit. |
| **Doctrine repositories** | Save/load/find/delete round-trip; cascade; unique constraints; `saveWeights` is bit-identical. | Real MariaDB, transactional rollback. |
| **Messenger handlers** | Picks the right message, loads state, calls the port, persists, advances state, re-dispatches or terminates. | Kernel boot, in-memory transport, real DB. |
| **UI / Controllers** | Route 200, form renders, POST triggers correct command, validation errors shown, redirects, CSRF. | `WebTestCase` + Foundry. |
| **End-to-end** | Ingest → CreateModel → TrainN → Predict. Loss decreases, output non-empty, output chars all in vocab. | Full kernel, real DB, real handlers. |

### 16.5 Mandatory acceptance tests (the "if any fails, the implementation is not done" list)

1. `AttentionLayerTest::testGradientMatchesFiniteDifference` — relative error < 1e-4 for `Wq,Wk,Wv,Wo` and input.
2. `FeedForwardLayerTest::testGradientMatchesFiniteDifference` — for `W1,b1,W2,b2`, same threshold.
3. `LayerNormTest::testGradientMatchesFiniteDifference`.
4. `EmbeddingLayerTest::testBackwardScattersGradientsCorrectly`.
5. `SoftmaxCrossEntropyTest::testGradientMatchesFiniteDifference`.
6. `AdamTest::testStepMatchesHandComputedUpdateForSimpleCase`.
7. `ModelTrainerTest::testLossDecreasesOverEpochsOnTinyCorpus` — `dModel=4, numLayers=1`, 50-char corpus, 50 epochs, final loss < initial loss × 0.5.
8. `CharacterTokenizerTest::testRoundTrip` and `testUnknownCharMapsToUnk`.
9. `VocabularyTest::testReservedTokensAreImmutable` — `<pad>,<bos>,<unk>` at 0,1,2.
10. `DoctrineLanguageModelRepositoryTest::testSaveAndLoadWeightsAreBitIdentical`.
11. `DoctrineTrainingJobRepositoryTest::testRecordEpochIsAppendOnly`.
12. `TrainModelMessageHandlerTest::testRedispatchesUntilTotalEpochsReached`.
13. `GeneratePredictionMessageHandlerTest::testGeneratedTextContainsOnlyVocabTokens`.
14. `EndToEnd/TrainAndPredictFlowTest::testHappyPath`.
15. `CorpusControllerTest::testPostingTextCreatesCorpusAndDispatchesIngestCommand`.
16. `ModelControllerTest::testCreatingModelAllocatesWeightRows`.
17. `PredictionControllerTest::testPollingEndpointReturnsDoneAfterWorkerRuns`.
18. `LanguageModelTest::testCannotTrainWhenStatusIsDraft` — state machine guard.
19. `PredictionTest::testSamplingStrategyEnumIsExhaustive`.
20. A test that runs `bin/console` with `XDEBUG_CONFIG` set and proves the debugger pipeline still works (smoke test).

### 16.6 Test conventions
- **Test names**: `testMethodName_doesWhat_whenCondition`. Example: `train_decreasesLoss_whenCorpusIsRepeatable`.
- **One assertion concept per test.** Multiple `assertSame` on the same concept are fine; split if concepts differ.
- **No mocking of value objects.** If a test mocks a VO, the design is wrong.
- **Mocks only for ports**: `LanguageModelRepository`, `TrainerPort`, `MessageBusInterface`, `Clock`.
- **Factories via Foundry** for any entity created in more than one test; raw `new Entity()` only inside unit tests of that entity.
- **Determinism**: all RNG accepts a seeded `Random\Randomizer`; tests pin the seed.
- **Time**: a `Clock` port is injected everywhere we touch time; tests use `MockClock`.
- **`@covers` on every test** so coverage reports are accurate.
- **No `sleep()` in tests.** Async behavior is asserted via `InMemoryTransport` or `WorkerTrait::runWorker()`.
- **PHPStan level 9** in CI alongside PHPUnit. No `@phpstan-ignore` without a written justification.
- **No `depends` annotations**; PHPUnit is run with `--order=defects` in CI.

### 16.7 phpunit.xml.dist
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
    colors="true"
    bootstrap="tests/bootstrap.php"
    cacheDirectory="var/.phpunit.cache"
    failOnRisky="true"
    failOnWarning="true">
    <php>
        <ini name="display_errors" value="1"/>
        <ini name="error_reporting" value="-1"/>
        <server name="APP_ENV" value="test" force="true"/>
        <server name="SHELL_VERBOSITY" value="-1"/>
        <server name="KERNEL_CLASS" value="App\Kernel"/>
    </php>
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="functional">
            <directory>tests/Functional</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

### 16.8 Hard coverage targets (enforced in CI)

| Path | Lines | Branches |
|---|---|---|
| `src/Shared/**` | 100 % | 100 % |
| `src/LanguageModel/Domain/**` | 100 % | 100 % |
| `src/LanguageModel/Infrastructure/Transformer/**` | 100 % | 100 % |
| `src/LanguageModel/Infrastructure/Tokenizer/**` | 100 % | 100 % |
| `src/LanguageModel/Application/**` | ≥ 90 % | ≥ 85 % |
| `src/LanguageModel/Infrastructure/Persistence/Doctrine/**` | ≥ 90 % | — |
| `src/LanguageModel/Infrastructure/Messenger/**` | ≥ 90 % | — |
| `src/LanguageModel/HttpInterface/**` | no minimum (but every route has ≥ 1 WebTest) | — |

CI runs:
```bash
vendor/bin/phpunit --coverage-text --coverage-clover=var/coverage/clover.xml
```
The Clover report is parsed by a small script (or by `infection`) that fails the build if any path falls below its threshold.

### 16.9 Anti-patterns (forbidden, with rationale)

- **`@codeCoverageIgnore` without a justification comment.**
- **Booting the kernel to test pure logic.** Domain and Transformer math MUST be unit-tested.
- **Mocking value objects.** VOs are data; mock the port.
- **`sleep()` to wait for async work.** Use `InMemoryTransport` or `WorkerTrait::runWorker()`.
- **Tests depending on execution order.** No `depends` annotations.
- **Touching the real filesystem** outside `tests/Fixtures/`. Use `Filesystem` with a temp dir.

### 16.10 PHPStan
`phpstan.neon`:
```neon
parameters:
    level: 9
    paths:
        - src
        - tests
    treatPhpDocTypesAsCertain: false
    symfony:
        containerXmlPath: var/cache/dev/App_KernelDevDebugContainer.xml
includes:
    - vendor/phpstan/phpstan-symfony/extension.neon
    - vendor/phpstan/phpstan-doctrine/extension.neon
```
PHPStan runs as a gate in CI. A failure blocks merge.

### 16.11 Local workflow (Makefile targets)
```makefile
.PHONY: test test-unit test-integration test-functional coverage stan stan-baseline

test:                          ## Run the full PHPUnit suite
	docker compose exec -T php vendor/bin/phpunit

test-unit:                     ## Run unit tests only
	docker compose exec -T php vendor/bin/phpunit --testsuite unit

test-integration:              ## Run integration tests only
	docker compose exec -T php vendor/bin/phpunit --testsuite integration

test-functional:               ## Run functional (WebTest) tests only
	docker compose exec -T php vendor/bin/phpunit --testsuite functional

coverage:                      ## Run tests with coverage
	docker compose exec -T php vendor/bin/phpunit --coverage-text --coverage-clover=var/coverage/clover.xml

stan:                          ## Run PHPStan
	docker compose exec -T php vendor/bin/phpstan analyse --memory-limit=1G

stan-baseline:                 ## Regenerate PHPStan baseline (use sparingly)
	docker compose exec -T php vendor/bin/phpstan analyse --generate-baseline --memory-limit=1G

test-xdebug:                   ## Smoke-test that Xdebug attaches during a unit test
	docker compose exec -T -e XDEBUG_CONFIG="client_host=host.docker.internal client_port=9003" php php -d xdebug.start_with_request=yes vendor/bin/phpunit --filter testSmoke --testsuite unit
```

### 16.12 CI (`.github/workflows/ci.yml` outline)
```yaml
name: CI
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      mariadb:
        image: mariadb:11.4
        env:
          MARIADB_ROOT_PASSWORD: root
          MARIADB_DATABASE: transformer
          MARIADB_USER: transformer
          MARIADB_PASSWORD: transformer
        ports: ['3306:3306']
        options: --health-cmd="healthcheck.sh --connect --innodb_initialized" --health-interval=5s --health-timeout=5s --health-retries=20
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: pdo_mysql, intl, opcache, zip
          coverage: pcov
      - run: composer install --no-progress --prefer-dist
      - run: vendor/bin/phpunit --testsuite unit
      - run: vendor/bin/phpunit --testsuite integration
      - run: vendor/bin/phpunit --testsuite functional
      - run: vendor/bin/phpunit --coverage-clover=var/coverage/clover.xml
      - run: vendor/bin/phpstan analyse --memory-limit=1G
```

---

## 17. Implementation Phases

Each phase is independently runnable. Each phase has a GATE — the phase is not done until the gate is green.

### Phase 0 — Bootstrap (~15 min)
- Files: `docker-compose.yml`, `docker/php/Dockerfile`, `docker/php/php.ini`, `docker/php/xdebug.ini`, `docker/apache/000-default.conf`, `docker/mariadb/conf.d/50-character-set.cnf`, `composer.json` (via `composer create-project`), `.env`, `.env.local.example`, `.gitignore`, `Makefile`, `phpunit.xml.dist`, `phpstan.neon`.
- Steps: `docker compose up -d --build`; `docker compose exec php composer create-project symfony/skeleton:^8.1 .` (or paste `composer.json`); install bundles from §2; `bin/console doctrine:database:create`.
- **GATE:** `curl http://localhost:8080/` returns Symfony welcome; `bin/console about` shows Symfony 8.1 / PHP 8.5; `vendor/bin/phpunit` (empty) exits 0.

### Phase 1 — Doctrine schema & repositories
- Files: all `.orm.xml` in `Infrastructure/Persistence/Doctrine/Mapping/`; the five `Doctrine*Repository` classes; one migration file.
- **GATE:** `vendor/bin/phpunit --testsuite integration` green; `DoctrineLanguageModelRepositoryTest::testSaveAndLoadWeightsAreBitIdentical` green; coverage of repositories ≥ 90 %; `bin/console doctrine:schema:validate` clean.

### Phase 2 — Domain & Application
- Files: all VOs/ARs in `Domain/`; all commands/queries/handlers in `Application/`; `EventRecordingMiddleware`; the three port interfaces; clock + system clock + mock clock.
- **GATE:** 100 % line/branch coverage on `Domain/`; ≥ 90 % lines on `Application/`; all `*HandlerTest` cover happy + every error branch; PHPStan level 9 clean.

### Phase 3 — HTTP UI (no math yet)
- Files: four controllers, three forms, eight Twig templates, three View DTOs, `base.html.twig`.
- **GATE:** Every route has a `WebTestCase`; create-corpus and create-model flows persist correctly; PHPStan clean.

### Phase 4 — Transformer math
- Files: `Tensor`, `EmbeddingLayer`, `LayerNorm`, `AttentionLayer`, `FeedForwardLayer`, `SoftmaxCrossEntropy`, `Adam`, plus their tests.
- **GATE:** All 6 finite-difference gradient tests pass with relative error < 1e-4; 100 % coverage of `Infrastructure/Transformer/`.

### Phase 5 — `ModelTrainer` + `TrainModelMessageHandler`
- Files: `ModelTrainer` (orchestrates forward+backward+Adam+persist), `TrainModelMessageHandler`, `AdamStateRepository` (for `m,v`), update to `LanguageModelRepository::saveWeights`.
- **GATE:** `ModelTrainerTest::testLossDecreasesOverEpochsOnTinyCorpus` green; `TrainModelMessageHandlerTest::testRedispatchesUntilTotalEpochsReached` green; `EndToEnd::testHappyPath` (training half) green.

### Phase 6 — `ModelPredictor` + `GeneratePredictionMessageHandler`
- Files: `ModelPredictor`, `GeneratePredictionMessageHandler`, prediction view updates.
- **GATE:** `GeneratePredictionMessageHandlerTest::testGeneratedTextContainsOnlyVocabTokens` green; `EndToEnd::testHappyPath` (prediction half) green; prediction controller `WebTestCase` green.

### Phase 7 — Polish
- Files: SVG loss chart macro, README with 5-minute tutorial, `.github/workflows/ci.yml`, optional `infection.json`.
- **GATE:** `make test` green; `make stan` green; coverage thresholds met; CI workflow added and passing locally via `act`.

---

## 18. Acceptance Criteria

The project is **done** when all of the following are true:

### 18.1 Functional
- [ ] A user can ingest a 200-char text via a form and see a vocabulary of expected size.
- [ ] A user can create a model with default hyperparameters and see weight rows in MariaDB.
- [ ] A user can dispatch a "train one epoch" action from the UI; the worker picks up the message, runs one epoch, persists updated weights and a loss value.
- [ ] A user can chain "train N epochs" until `status = Trained`.
- [ ] A user can submit a prompt; the worker generates a non-empty string composed only of vocabulary characters.
- [ ] The loss decreases monotonically (within an epsilon) over at least 10 epochs on a deterministic 50-char corpus.
- [ ] Killing the worker mid-training and restarting it continues from the last persisted epoch.

### 18.2 Architectural
- [ ] `src/LanguageModel/Domain/**` contains zero `use` statements pointing to `Symfony` or `Doctrine`.
- [ ] All five domain repositories are interfaces; concrete classes live under `Infrastructure/Persistence/Doctrine/Repository/`.
- [ ] `Application/CommandHandler/*` depends only on `Domain` types and `Application/Port` interfaces.
- [ ] No controller imports anything from `Infrastructure/Transformer` directly; controllers dispatch commands and read views.
- [ ] Two message buses (`command.bus`, `query.bus`) are configured and used as documented.

### 18.3 Test
- [ ] `vendor/bin/phpunit` exits 0.
- [ ] All 20 acceptance tests in §16.5 pass.
- [ ] Coverage thresholds in §16.8 are met or exceeded.
- [ ] `vendor/bin/phpstan analyse` exits 0 at level 9.
- [ ] CI workflow runs all of the above on push/PR.

### 18.4 Developer Experience
- [ ] `make test` and `make stan` work from a fresh clone after `make up`.
- [ ] Setting a breakpoint in `AttentionLayer::backward` in VS Code and clicking "Train one epoch" in the UI breaks on that line.
- [ ] Browser-side Xdebug Helper extension works for HTTP debugging.
- [ ] `php bin/console` autocompletion works in the container shell.

---

## 19. How To Use the App

```bash
# 1. Clone and start
git clone <repo> transformer-php
cd transformer-php
cp .env.local.example .env.local
docker compose up -d --build

# 2. Install PHP deps and migrate
docker compose exec php composer install
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction

# 3. Open the app
open http://localhost:8080

# 4. Start the two workers in two terminal tabs
docker compose exec php bin/console messenger:consume async_training -vv
docker compose exec php bin/console messenger:consume async_inference -vv

# 5. In the UI:
#    a) /corpus/new  -> paste a 200-char text
#    b) /model/new   -> accept defaults
#    c) /model/{id}  -> click "Train 10 epochs"
#    d) /prediction/new?model={id} -> prompt -> "Generate"
#    e) /prediction/{id} -> wait for "Done", see generated text

# 6. To step-debug:
#    a) VS Code -> Run & Debug -> "Debug Messenger worker: training"
#    b) Set a breakpoint anywhere in src/LanguageModel/Infrastructure/Transformer/
#    c) In the UI, click "Train one epoch" -> worker breaks on your line
```

---

## 20. Decisions Log

| # | Decision | Alternatives considered | Chosen because |
|---|---|---|---|
| 1 | Tokenization: character-level | word-level, BPE | Simplest; < 100 LOC; ideal for teaching. |
| 2 | Training: full backprop, hand-derived grads | simplified contrastive, autograd engine | Real learning; "the math actually works". |
| 3 | IDE: VS Code | PhpStorm | User choice. |
| 4 | Model storage: one row per weight | JSON column, BLOB | Best for inspection and step-debugging. |
| 5 | PHP 8.5 + Symfony 8.1 | Symfony 7.4 LTS | 8.1 is current stable; works on 8.5. |
| 6 | Layer norm: pre-norm | post-norm | Pre-norm has simpler gradient flow; easier to debug. |
| 7 | Single-head attention by default | Multi-head from the start | Defaults to 1 head; user can set `numHeads` and the code still works. |
| 8 | Adam (no bias correction) | SGD, AdamW | Simpler, sufficient at this scale. |
| 9 | ReLU FFN | GeLU, SwiGLU | ReLU is the simplest non-linearity to teach. |
| 10 | XML Doctrine mappings | PHP attributes | Cleaner for many tables; no annotation imports. |
| 11 | `dama/doctrine-test-bundle` for tests | manual cleanup | Fast, isolated, deterministic. |
| 12 | `zenstruck/foundry` for test entities | hand-written factories | Less boilerplate. |
| 13 | Two message buses (`command.bus`, `query.bus`) | one bus | Text-book CQRS. |
| 14 | Pcov for coverage, Xdebug for debug | Xdebug for both | Pcov is faster and does not interfere with the step debugger. |
| 15 | Tailwind via CDN | Sass build | Zero build step. |
| 16 | Re-dispatch TrainModelMessage per epoch | one big job | Allows killing the worker between epochs (vital for step-debugging). |
| 17 | Tests in English `testMethodName_doesWhat_whenCondition` | snake_case only | Matches PHPUnit 11 idioms. |

---

## 21. Open Items To Confirm

1. **PHPUnit 11.5** as test runner — confirm.
2. **PHPStan level 9** as the static gate — confirm.
3. **zenstruck/foundry** for test factories — confirm (or: hand-written factories).
4. **CI provider = GitHub Actions** with MariaDB service container — confirm (or: GitLab CI, or "no CI, gates in `Makefile` only").
5. **Infection** as an opt-in mutation gate (not blocking) — confirm (or: drop it).
6. **Sample corpus shipped in the repo** (`tests/Fixtures/sample_corpus.txt`, ~200 chars of a small poem or pangram) — confirm.
7. **SVG loss chart in the template** (no JS chart lib) — confirm.
8. **All UI strings in English** (no i18n) — confirm.

---

## 22. Appendix: ASCII Diagrams

### 22.1 Hexagonal flow
```
+--------------------+        +--------------------+        +------------------+
|  HttpInterface     |        |  Application       |        |  Domain          |
|  (Twig, Forms)     |  --->  |  (Commands /       |  --->  |  (ARs, VOs,      |
|                    |        |   Queries,         |        |   Events,        |
|  - Dashboard       |        |   Handlers)        |        |   Repository     |
|  - Corpus          |        |                    |        |   interfaces)    |
|  - Model           |        |  - IngestText      |        |                  |
|  - Prediction      |        |  - CreateModel     |        +------------------+
+--------------------+        |  - TrainModel      |                  ^
        |                     |  - GeneratePred.   |                  |
        | dispatch            |  - Queries         |                  |
        v                     +--------------------+                  |
+--------------------+                 |                              |
|  Infrastructure    |  implements     |                              |
|  (Symfony, DB,     |  ports &  <-----+------------------------------+
|   Messenger,       |
|   Transformer)     |
|                    |
|  - Doctrine repos  |
|  - CharacterToken. |
|  - Tensor, Attn..  |
|  - Messenger hndl. |
+--------------------+
```

### 22.2 Transformer layer (per block)
```
       ┌──────────────────────────────────────────────┐
       │                                              │
       │  x ─► Embedding(tok) ─┐                      │
       │                      ├─► (+) ─► LayerNorm ─► MultiHeadAttention ─► (+) ─► LayerNorm ─► FFN ─► (+) ─► y
       │  x ─► Embedding(pos) ─┘   ▲                                 ▲                  ▲
       │                          │                                 │                  │
       │                          └─ residual                       └─ residual        └─ residual
       └──────────────────────────────────────────────┘
```

### 22.3 Messenger topology
```
        +---------------+          +-------------------------+
HTTP →  |  Controller   | dispatch |  command.bus            |
        |  (creates Cmd)| ───────► |  - IngestTextCommand    |
        +---------------+          |  - CreateModelCommand   |
                                    |  - TrainModelCommand    |
                                    |  - GeneratePrediction   |
                                    +-----------+-------------+
                                                |
                                                v
                          +---------------------+----------------------+
                          |                                            |
                          v                                            v
                +---------------------+                  +-------------------------+
                |  async_training     |                  |  async_inference        |
                |  (DB transport)     |                  |  (DB transport)         |
                |  queue: training    |                  |  queue: inference       |
                +----------+----------+                  +------------+------------+
                           |                                          |
                           v                                          v
                +---------------------+                  +-------------------------+
                | worker: training    |                  | worker: inference       |
                | TrainModelMessage   |                  | GeneratePredictionMsg   |
                | Handler             |                  | Handler                 |
                +---------------------+                  +-------------------------+
```

### 22.4 Training epoch loop
```
TrainModelMessageHandler
  ├─ load job, model, corpus, vocab
  ├─ tokenize corpus once (cache in job)
  └─ for epoch in [job.epoch, totalEpochs):
       ├─ sample window: x[0..T-1], target=x[1..T]
       ├─ forward:    logits = ModelTrainer.forward(model, x)
       ├─ loss:      L = softmax_cross_entropy(logits, target)
       ├─ backward:  grads = ModelTrainer.backward(L)
       ├─ adam step: weights = Adam.step(weights, grads)
       ├─ persist:   saveWeights(modelId, weights)
       ├─ record:    recordEpoch(jobId, epoch, L)
       ├─ advance:   job.epoch += 1
       └─ if epoch < totalEpochs:
            re-dispatch TrainModelMessage  ──► exits cleanly between epochs
          else:
            model.markTrained(); job.complete()
```

### 22.5 Prediction loop
```
GeneratePredictionMessageHandler
  ├─ load prediction, model, vocab
  ├─ encode prompt → TokenSequence
  └─ while generated.length < maxNewTokens and last != <unk>:
       ├─ forward:    logits = ModelPredictor.forward(model, seq)
       ├─ sample:     nextId = Sampler.sample(logits[-1], strategy, topK?)
       └─ if nextId == <pad>: break
       seq = seq.append(nextId)
  ├─ detokenize(seq) → generatedText
  └─ prediction.complete(generatedText)
```

---

**End of SPECKIT.**
