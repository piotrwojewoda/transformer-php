# Implementation Checklist

Use this as the working plan. Tick items as you go. Each phase has a GATE that must be green before moving to the next.

Legend: `[ ]` todo, `[x]` done, `[~]` in progress, `[!]` blocked.

---

## Phase 0 — Bootstrap

- [ ] `docker-compose.yml` written and `docker compose up -d --build` succeeds
- [ ] `docker/php/Dockerfile` builds with PHP 8.5
- [ ] `docker/php/xdebug.ini` loads (verify with `php -m | grep xdebug`)
- [ ] `docker/apache/000-default.conf` points to `/app/public`
- [ ] `docker/mariadb/conf.d/50-character-set.cnf` is mounted
- [ ] MariaDB healthcheck passes within 20 retries
- [ ] `composer create-project symfony/skeleton:^8.1 .` (or `composer.json` committed)
- [ ] All required bundles installed (`orm-pack`, `messenger`, `twig-bundle`, `form`, `validator`, `asset`, `uid`, `maker-bundle`, `test-pack`, `phpunit:^11.5`, `dama-doctrine-test-bundle`, `foundry`, `phpstan`, `phpstan-symfony`, `phpstan-doctrine`)
- [ ] `.env`, `.env.local.example`, `.env.test` written
- [ ] `.gitignore` covers `vendor/`, `var/`, `.env.local`, `var/coverage/`
- [ ] `phpunit.xml.dist` written
- [ ] `phpstan.neon` written
- [ ] `Makefile` written with `test`, `test-unit`, `test-integration`, `test-functional`, `coverage`, `stan`, `test-xdebug` targets
- [ ] `tests/bootstrap.php` written
- [ ] `bin/console about` shows Symfony 8.1 / PHP 8.5
- [ ] `curl http://localhost:8080/` returns 200
- [ ] Empty PHPUnit suite passes

**GATE 0:** `make test` exits 0 (empty suite is fine); PHP version is 8.5; Symfony version is 8.1.

---

## Phase 1 — Doctrine schema & repositories

- [ ] `src/Shared/Domain/{AggregateRoot, DomainEvent, Clock}.php` written
- [ ] `src/Shared/Infrastructure/Clock/{SystemClock, MockClock}.php` written
- [ ] All Doctrine entities under `src/LanguageModel/Infrastructure/Persistence/Doctrine/Entity/` written
- [ ] All XML mappings under `src/LanguageModel/Infrastructure/Persistence/Doctrine/Mapping/` written
- [ ] `migrations/Version...php` generated and reviewed
- [ ] `doctrine:migrations:migrate --no-interaction` succeeds
- [ ] `bin/console doctrine:schema:validate` is clean
- [ ] `DoctrineLanguageModelRepository` written, including `saveWeights` and `loadWeights`
- [ ] `DoctrineCorpusRepository` written
- [ ] `DoctrineVocabularyRepository` written
- [ ] `DoctrineTrainingJobRepository` written, including `recordEpoch`
- [ ] `DoctrinePredictionRepository` written
- [ ] All five repository interfaces in `src/LanguageModel/Domain/Repository/` written
- [ ] Repository implementations wired in `config/services.yaml`
- [ ] `tests/Integration/LanguageModel/Infrastructure/Persistence/Doctrine/DoctrineLanguageModelRepositoryTest.php` passes
- [ ] `tests/Integration/LanguageModel/Infrastructure/Persistence/Doctrine/DoctrineCorpusRepositoryTest.php` passes
- [ ] `tests/Integration/LanguageModel/Infrastructure/Persistence/Doctrine/DoctrineVocabularyRepositoryTest.php` passes
- [ ] `tests/Integration/LanguageModel/Infrastructure/Persistence/Doctrine/DoctrineTrainingJobRepositoryTest.php` passes
- [ ] `tests/Integration/LanguageModel/Infrastructure/Persistence/Doctrine/DoctrinePredictionRepositoryTest.php` passes
- [ ] `testSaveAndLoadWeightsAreBitIdentical` test passes

**GATE 1:** `make test-integration` exits 0; coverage of `Persistence/Doctrine/**` ≥ 90 %; `doctrine:schema:validate` clean.

---

## Phase 2 — Domain & Application

### Domain value objects
- [ ] `ModelId, CorpusId, TrainingJobId, PredictionId` (uuid v7)
- [ ] `TokenId, Character, TokenSequence`
- [ ] `ModelConfig` with validation
- [ ] `TrainingConfig`
- [ ] `TrainingLoss`
- [ ] `SamplingConfig`, `SamplingStrategy` enum
- [ ] `Weights` with `get` and `withUpdate`
- [ ] `ModelStatus` enum

### Domain aggregates
- [ ] `Corpus` (AR) with `TextIngested` event
- [ ] `Vocabulary` (AR) with reserved tokens
- [ ] `LanguageModel` (AR) with state machine
- [ ] `TrainingJob` (AR)
- [ ] `Prediction` (AR)

### Domain events
- [ ] `TextIngested, ModelCreated, ModelTrained, EpochCompleted, PredictionGenerated`

### Application — Ports
- [ ] `TokenizerPort`
- [ ] `TrainerPort`
- [ ] `PredictorPort`

### Application — Commands & Handlers
- [ ] `IngestTextCommand` + `IngestTextHandler`
- [ ] `CreateModelCommand` + `CreateModelHandler`
- [ ] `TrainModelCommand` + `TrainModelHandler`
- [ ] `GeneratePredictionCommand` + `GeneratePredictionHandler`

### Application — Queries & Handlers
- [ ] `ListModelsQuery` + `ListModelsHandler`
- [ ] `GetModelQuery` + `GetModelHandler`
- [ ] `GetVocabQuery` + `GetVocabHandler`
- [ ] `GetTrainingHistoryQuery` + `GetTrainingHistoryHandler`
- [ ] `GetPredictionQuery` + `GetPredictionHandler`

### Application — Middleware & buses
- [ ] `EventRecordingMiddleware` written
- [ ] `command.bus` and `query.bus` configured in `messenger.yaml`
- [ ] `services.yaml` wires ports and repositories

### Tests
- [ ] All ARs have unit tests for invariants, mutators, events, edge cases
- [ ] All VOs have unit tests
- [ ] All command handlers have ≥ 4 tests (happy + every error branch + state-guard violation + dispatcher call)
- [ ] All query handlers have ≥ 2 tests

**GATE 2:** `make test-unit` exits 0; coverage of `Domain/**` = 100 % lines & branches; coverage of `Application/**` ≥ 90 % lines; `make stan` exits 0.

---

## Phase 3 — HTTP UI (no math)

### Controllers
- [ ] `DashboardController::index`
- [ ] `CorpusController::{new,show}`
- [ ] `ModelController::{list,new,show,trainOneEpoch,trainN}`
- [ ] `PredictionController::{new,show}`

### Forms
- [ ] `IngestTextType`
- [ ] `CreateModelType` (includes `TrainingConfig` sub-form)
- [ ] `GeneratePredictionType`

### Read models / View DTOs
- [ ] `ModelView`
- [ ] `TrainingHistoryView`
- [ ] `PredictionView`

### Templates
- [ ] `templates/base.html.twig` (Tailwind CDN, nav, flash messages)
- [ ] `corpus/new.html.twig`
- [ ] `corpus/show.html.twig`
- [ ] `model/list.html.twig`
- [ ] `model/new.html.twig`
- [ ] `model/detail.html.twig` (with placeholder loss chart)
- [ ] `prediction/new.html.twig`
- [ ] `prediction/show.html.twig` (with `<meta refresh>`)

### Routes
- [ ] All routes in `config/routes.yaml`

### Tests
- [ ] `DashboardControllerTest` (200, KPI counts)
- [ ] `CorpusControllerTest` (GET, POST happy, POST validation error, dispatches `IngestTextCommand`)
- [ ] `ModelControllerTest` (GET, POST happy, POST validation error, dispatches `CreateModelCommand`, train-one-epoch button)
- [ ] `PredictionControllerTest` (GET, POST happy, polling returns 200 while running, returns done after worker)

**GATE 3:** `make test-functional` exits 0; all 4 controller test classes green; `make stan` clean.

---

## Phase 4 — Transformer math

### Core types
- [ ] `Tensor` (row-major `array<float, 2>` wrapper with shape and helpers)

### Layers
- [ ] `EmbeddingLayer` (forward + backward with scatter)
- [ ] `LayerNorm` (forward + backward)
- [ ] `AttentionLayer` (forward + backward, single-head, causal mask)
- [ ] `FeedForwardLayer` (forward + backward, ReLU)
- [ ] `SoftmaxCrossEntropy` (forward + backward combined)

### Optimizer
- [ ] `Adam` (with `m, v` state)

### Tests (mandatory)
- [ ] `TensorTest` (shape, add, matmul, transpose, apply)
- [ ] `EmbeddingLayerTest` (incl. `testBackwardScattersGradientsCorrectly`)
- [ ] `LayerNormTest` (incl. finite-difference)
- [ ] `AttentionLayerTest` (incl. finite-difference for Wq, Wk, Wv, Wo, input)
- [ ] `FeedForwardLayerTest` (incl. finite-difference)
- [ ] `SoftmaxCrossEntropyTest` (incl. finite-difference)
- [ ] `AdamTest` (incl. `testStepMatchesHandComputedUpdateForSimpleCase`)

**GATE 4:** All 6 finite-difference tests pass with relative error < 1e-4; coverage of `Infrastructure/Transformer/**` = 100 %.

---

## Phase 5 — Training wiring

- [ ] `ModelTrainer` (orchestrates forward + backward + Adam + persist)
- [ ] `TrainerPort` implementation in `ModelTrainer`
- [ ] `TrainModelMessageHandler`
- [ ] Re-dispatch logic: handler re-queues `TrainModelMessage` if `epoch < totalEpochs`
- [ ] `AdamStateRepository` (or equivalent) to persist `m, v` per parameter
- [ ] `LanguageModel::applyGradient(Weights)` enforces state machine
- [ ] `TrainingJob::recordEpoch` is append-only

### Tests
- [ ] `ModelTrainerTest::testLossDecreasesOverEpochsOnTinyCorpus` passes
- [ ] `TrainModelMessageHandlerTest::testRedispatchesUntilTotalEpochsReached` passes
- [ ] `TrainModelMessageHandlerTest::testMarksModelTrainedAfterFinalEpoch` passes
- [ ] `EndToEnd/TrainAndPredictFlowTest::testTrainingHalf` passes

**GATE 5:** Loss decreases on a deterministic 50-char corpus; model reaches `Trained` status; `EndToEnd` training half green.

---

## Phase 6 — Prediction wiring

- [ ] `ModelPredictor` (greedy + top-k sampling)
- [ ] `PredictorPort` implementation in `ModelPredictor`
- [ ] `GeneratePredictionMessageHandler`
- [ ] `Prediction::complete` and `Prediction::fail` enforce state machine
- [ ] `prediction/show.html.twig` polls while not done

### Tests
- [ ] `ModelPredictorTest::testGeneratesAtLeastOneToken` passes
- [ ] `ModelPredictorTest::testStopsAtPadToken` passes
- [ ] `ModelPredictorTest::testTopKRespectsK` passes
- [ ] `GeneratePredictionMessageHandlerTest::testGeneratedTextContainsOnlyVocabTokens` passes
- [ ] `GeneratePredictionMessageHandlerTest::testPredictionMarkedDoneAfterSuccess` passes
- [ ] `EndToEnd/TrainAndPredictFlowTest::testPredictionHalf` passes

**GATE 6:** End-to-end test green; `make test` exits 0; `make stan` exits 0.

---

## Phase 7 — Polish

- [ ] Inline SVG loss chart macro (no JS)
- [ ] `<meta refresh>` polling on prediction/show (no JS)
- [ ] `README.md` with 5-minute quickstart
- [ ] `SPECKIT.md` cross-references in README
- [ ] `.github/workflows/ci.yml` runs unit, integration, functional, coverage, stan
- [ ] (optional) `infection.json` for mutation testing
- [ ] `tests/Fixtures/sample_corpus.txt` shipped (200+ chars)
- [ ] `bin/console cache:clear --env=prod` succeeds
- [ ] `bin/console lint:container` succeeds
- [ ] `bin/console debug:messenger` shows all handlers and their transports
- [ ] `bin/console debug:autowiring` shows all ports and repositories

**GATE 7:** All previous gates still hold. CI workflow file exists. `make test-xdebug` smoke test passes (or is intentionally skipped with a justification comment).

---

## Final acceptance

- [ ] `make test` exits 0
- [ ] `make stan` exits 0
- [ ] Coverage thresholds in `SPECKIT.md §16.8` are met
- [ ] All 20 mandatory tests in `SPECKIT.md §16.5` are green
- [ ] Step-debugging from VS Code breaks on a line in `AttentionLayer::backward` while the worker is running
- [ ] Browser-side Xdebug Helper breaks on a line in `CorpusController::new` when triggered
- [ ] Pasting the sample corpus, creating a model with defaults, training 50 epochs, and predicting 30 chars produces a non-empty, all-vocab output
- [ ] The loss chart on the model detail page shows a monotonically (within epsilon) decreasing curve
- [ ] `git log` shows commits in the order of the phases (or rebased cleanly)
