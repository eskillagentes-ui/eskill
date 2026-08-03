-- Pregão P2: backfill provenance + source obrigatório (sem DEFAULT silencioso)
-- Preferir: php bin/pregao-backfill-seed.php
-- Critério documentado em docs/pregao-provenance-backfill.md

-- 1) Marca smoke como seed (janela Fase 2 + assinatura pregao_emit_sale)
UPDATE `pregao_events`
SET `source` = 'seed'
WHERE `source` <> 'seed'
  AND (
    `payload` LIKE '%Teste Hermes%'
    OR `payload` LIKE '%"order_id":"T%'
    OR `payload` LIKE '%"sku":"MLB1"%'
  );

-- 2) Remove DEFAULT silencioso — novos INSERTs devem informar source
ALTER TABLE `pregao_events`
  MODIFY COLUMN `source` varchar(32) NOT NULL;
