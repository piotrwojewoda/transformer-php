# Quickstart — From Zero to "Hello, World" in 5 Minutes

This guide assumes the project is already implemented. If you are implementing it, see [`CHECKLIST.md`](./CHECKLIST.md) and [`SPECKIT.md`](../SPECKIT.md).

## Prerequisites
- Docker Engine 24+ with the Compose plugin.
- VS Code with the "PHP Debug" extension (Felix Becker).
- A web browser. The "Xdebug Helper" browser extension is recommended but optional.

## Step 1 — Start the stack
```bash
cp .env.local.example .env.local
docker compose up -d --build
docker compose exec php composer install
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```
Open `http://localhost:8080`. You should see the dashboard.

## Step 2 — Ingest a corpus
1. Go to `http://localhost:8080/corpus/new`.
2. Enter name: `pangram`.
3. Paste into the textarea:
   > The quick brown fox jumps over the lazy dog. Pack my box with five dozen liquor jugs. How vexingly quick daft zebras jump!
4. Submit. You should be redirected to the corpus detail page showing the vocabulary.

## Step 3 — Create a model
1. Go to `http://localhost:8080/model/new`.
2. Accept all defaults (`dModel=8`, `numHeads=1`, `numLayers=1`, `dFf=16`, `maxSeqLen=32`).
3. Submit. You should be redirected to the model detail page.
4. Open a separate terminal and run:
   ```sql
   docker compose exec mariadb mariadb -u transformer -ptransformer transformer \
     -e "SELECT COUNT(*) AS rows_per_table FROM model_attention_weights WHERE model_id = 1 GROUP BY matrix;"
   ```
   You should see 4 rows (`wq, wk, wv, wo`) each with 64 entries (8×8 matrix).

## Step 4 — Start the training worker
In a new terminal tab:
```bash
docker compose exec php bin/console messenger:consume async_training -vv
```
Leave this running. It will pick up `TrainModelMessage`s from the `training` queue.

## Step 5 — Train 50 epochs
On the model detail page, click **"Train 10 epochs"** five times (or change the value to 50 if the form allows it). Each click:
1. Dispatches a `TrainModelCommand` synchronously.
2. The handler creates a `TrainingJob` and dispatches a `TrainModelMessage` to the queue.
3. The worker picks it up, trains one epoch, persists weights and loss, then re-dispatches if more epochs remain.

Watch the loss chart on the page update after each epoch.

## Step 6 — Generate a prediction
1. Once the model is `Trained`, go to `http://localhost:8080/prediction/new?model=1`.
2. Enter prompt: `The quick brown`.
3. Set `sampling = greedy`, `max_new_tokens = 30`.
4. Submit.
5. You are redirected to a status page that auto-refreshes once per second.
6. The page should resolve to a generated string. It will not be English (the corpus and model are tiny) but it should be non-empty and contain only characters from the corpus.

## Step 7 (optional) — Step-debug the training
1. Open VS Code at the project root.
2. In the Run & Debug panel, choose **"Debug Messenger worker: training"** and press the green play button.
3. Set a breakpoint in `src/LanguageModel/Infrastructure/Transformer/AttentionLayer.php` on the first line of the `backward` method.
4. In the UI, click **"Train one epoch"**.
5. The worker breaks on your breakpoint. Step with F10 / F11.
6. Inspect `$this->cache` in the Variables panel to see the saved forward activations.
7. Watch the gradients flow through `LayerNorm`, `Embedding`, `FFN`, and the final projection.
8. Continue — the worker finishes the epoch, persists the weights, and re-dispatches.

## Common pitfalls

| Symptom | Fix |
|---|---|
| Browser request does not break in VS Code | Install the "Xdebug Helper" extension and enable it for `http://localhost:8080`. |
| VS Code shows `Could not open debugger port`. | Make sure the "Listen for Xdebug" or "Debug Messenger worker" configuration is the active one (the green play button). |
| Worker is silent | Run `docker compose exec mariadb mariadb -u transformer -ptransformer transformer -e "SELECT * FROM messenger_messages;"`. The message may still be `available_at` in the future, or the worker may not be running. |
| Training loss does not decrease | Verify the corpus is non-empty and the vocabulary size is > 4. A corpus of a single character will not teach the model anything. |
| MariaDB rejects the connection | `docker compose logs mariadb` — usually means the healthcheck did not pass. Wait 10 seconds and retry. |
| `composer install` fails on PHP version mismatch | Confirm `php -v` inside the container reports `8.5.x`. If not, rebuild with `docker compose build --no-cache php`. |

## Tear down
```bash
docker compose down           # keeps volumes
docker compose down -v        # nukes volumes too (next start is a fresh DB)
```
