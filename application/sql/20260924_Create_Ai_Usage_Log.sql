-- =============================================================================
-- ai_usage_log — one row per OpenAI API call across every AI feature, so the
-- owner-only "AI Cost & Usage" page (controllers/Ai_Usage.php) can report the
-- total spend and token usage in one place.
--
-- Going forward each AI service (Competitor / Customer Analysis, FAQ Search,
-- FAQ Suggestions + their embeddings) inserts a row here right after each call.
-- The INSERT ... SELECT blocks below backfill the history that is already stored
-- in the per-feature tables so the page is meaningful from day one.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `ai_usage_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `feature`       VARCHAR(64) NOT NULL COMMENT 'Competitor Analysis, Customer Analysis, FAQ Search, FAQ Suggestions, ...',
  `provider`      VARCHAR(32) NOT NULL DEFAULT 'openai',
  `model`         VARCHAR(100) NULL DEFAULT NULL,
  `input_tokens`  INT NOT NULL DEFAULT 0,
  `output_tokens` INT NOT NULL DEFAULT 0,
  `total_tokens`  INT NOT NULL DEFAULT 0,
  `cost_usd`      DECIMAL(14,6) NOT NULL DEFAULT 0,
  `status`        VARCHAR(20) NOT NULL DEFAULT 'ok',
  `reference_id`  VARCHAR(64) NULL DEFAULT NULL COMMENT 'source row id (competitor_analyses.id, ...) for backfilled rows',
  `created_by`    INT NULL DEFAULT NULL COMMENT 'admin.AdminID who triggered the call',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_usage_feature` (`feature`),
  KEY `idx_ai_usage_model` (`model`),
  KEY `idx_ai_usage_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: Competitor Analysis (per-analysis totals already stored).
INSERT INTO `ai_usage_log`
  (feature, provider, model, input_tokens, output_tokens, total_tokens, cost_usd, status, reference_id, created_by, created_at)
SELECT
  'Competitor Analysis', 'openai', ca.model,
  ca.input_tokens, ca.output_tokens, (ca.input_tokens + ca.output_tokens), ca.cost_usd,
  CASE WHEN ca.status = 'done' THEN 'ok' ELSE ca.status END,
  CAST(ca.id AS CHAR), ca.created_by, ca.created_at
FROM `competitor_analyses` ca
WHERE NOT EXISTS (
  SELECT 1 FROM `ai_usage_log` l
  WHERE l.feature = 'Competitor Analysis' AND l.reference_id = CAST(ca.id AS CHAR) COLLATE utf8mb4_unicode_ci
);

-- Backfill: Customer Analysis.
INSERT INTO `ai_usage_log`
  (feature, provider, model, input_tokens, output_tokens, total_tokens, cost_usd, status, reference_id, created_by, created_at)
SELECT
  'Customer Analysis', 'openai', cu.model,
  cu.input_tokens, cu.output_tokens, (cu.input_tokens + cu.output_tokens), cu.cost_usd,
  CASE WHEN cu.status = 'done' THEN 'ok' ELSE cu.status END,
  CAST(cu.id AS CHAR), cu.created_by, cu.created_at
FROM `customer_analyses` cu
WHERE NOT EXISTS (
  SELECT 1 FROM `ai_usage_log` l
  WHERE l.feature = 'Customer Analysis' AND l.reference_id = CAST(cu.id AS CHAR) COLLATE utf8mb4_unicode_ci
);

-- Backfill: FAQ Suggestions (runs store only aggregate cost, no token split).
INSERT INTO `ai_usage_log`
  (feature, provider, model, input_tokens, output_tokens, total_tokens, cost_usd, status, reference_id, created_by, created_at)
SELECT
  'FAQ Suggestions', 'openai', r.Model,
  0, 0, 0, COALESCE(r.CostUsd, 0),
  CASE WHEN r.RunState = 'done' THEN 'ok' ELSE r.RunState END,
  CAST(r.RunID AS CHAR), r.InsertBy, r.InsertDate
FROM `faq_suggestion_runs` r
WHERE COALESCE(r.CostUsd, 0) > 0
  AND NOT EXISTS (
    SELECT 1 FROM `ai_usage_log` l
    WHERE l.feature = 'FAQ Suggestions' AND l.reference_id = CAST(r.RunID AS CHAR) COLLATE utf8mb4_unicode_ci
  );
