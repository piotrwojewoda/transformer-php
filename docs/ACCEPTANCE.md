# Acceptance Criteria

The project is "done" only when every box below is checked. These mirror and expand `SPECKIT.md §18`. Re-verify at the end of every phase.

## Functional

- [ ] A user can ingest a 200-character text via the form and see a vocabulary of expected size.
- [ ] A user can create a model with default hyperparameters and see weight rows in MariaDB.
- [ ] A user can click "Train one epoch" in the UI; the worker picks up the message, runs one epoch, and persists updated weights plus a loss value.
- [ ] A user can chain "Train 10 epochs" until `status = Trained`.
- [ ] A user can submit a prompt; the worker generates a non-empty string composed only of vocabulary characters.
- [ ] Loss decreases over at least 10 epochs on a deterministic 50-character corpus (final loss < initial loss × 0.5).
- [ ] Killing the worker mid-training and restarting it continues from the last persisted epoch.
- [ ] A failed training job leaves the model in `Failed` status with an `errorMessage`; the UI displays it.
- [ ] A failed prediction leaves the prediction in `Failed` status with an `errorMessage`; the UI displays it.

## Architectural

- [ ] `src/LanguageModel/Domain/**` contains zero `use` statements that point to `Symfony` or `Doctrine`.
- [ ] All five domain repositories are interfaces; concrete classes live under `src/LanguageModel/Infrastructure/Persistence/Doctrine/Repository/`.
- [ ] `src/LanguageModel/Application/CommandHandler/**` depends only on `Domain` types and `Application/Port` interfaces.
- [ ] No controller imports anything from `Infrastructure/Transformer` directly; controllers dispatch commands and read views.
- [ ] Two message buses (`command.bus`, `query.bus`) are configured and used as documented.
- [ ] No raw SQL is written in `Application/` or `HttpInterface/`. All persistence goes through `Domain/Repository/` interfaces.
- [ ] No `new \DateTimeImmutable()` outside `Shared/Infrastructure/Clock/SystemClock.php`. Time is injected everywhere.
- [ ] No `mt_rand`, `random_int`, `rand` in production code outside of a constructor-injected `Random\Randomizer`.

## Test

- [ ] `vendor/bin/phpunit` exits 0 with all three suites.
- [ ] All 20 acceptance tests in `SPECKIT.md §16.5` are green.
- [ ] Coverage thresholds in `SPECKIT.md §16.8` are met or exceeded.
- [ ] `vendor/bin/phpstan analyse` exits 0 at level 9.
- [ ] No `@codeCoverageIgnore` annotations without a written justification comment in the same line or block.
- [ ] No test mocks a value object.
- [ ] No test uses `sleep()`.
- [ ] No test uses the `depends` annotation.
- [ ] Every test has a `@covers` annotation.
- [ ] CI workflow runs all of the above on push and pull_request.

## Developer Experience

- [ ] `make test` and `make stan` work from a fresh clone after `make up`.
- [ ] Setting a breakpoint in `AttentionLayer::backward` in VS Code and clicking "Train one epoch" in the UI breaks on that line.
- [ ] Browser-side "Xdebug Helper" extension breaks inside a controller when enabled.
- [ ] `bin/console` autocompletion works inside the container shell.
- [ ] `bin/console debug:messenger` lists all handlers with their transports.
- [ ] `bin/console debug:container --parameter=kernel.project_dir` returns `/app`.

## Performance (non-blocking)

These are not strict gates; they are sanity checks to confirm the implementation is "tiny but not pathological".

- [ ] One epoch on the default model with a 200-character corpus takes < 2 seconds in the worker.
- [ ] `bin/console doctrine:schema:validate` runs in < 1 second.
- [ ] The full unit test suite runs in < 30 seconds.
- [ ] The full integration + functional test suite runs in < 5 minutes.

## Security (minimal)

- [ ] All forms have CSRF tokens.
- [ ] No user input is reflected verbatim in templates without `|e`.
- [ ] `.env.local` is gitignored.
- [ ] `APP_SECRET` in `.env.local.example` is a placeholder, not a real secret.
- [ ] `expose_php = Off` in `php.ini`.

## Documentation

- [ ] `README.md` has a 5-minute quickstart.
- [ ] `SPECKIT.md` cross-references README and docs.
- [ ] `docs/QUICKSTART.md` works for a fresh clone.
- [ ] `docs/CHECKLIST.md` is fully ticked.
- [ ] `docs/DECISIONS.md` explains every non-obvious choice.
- [ ] Inline code comments are limited to "why", not "what" (the code is the "what").
