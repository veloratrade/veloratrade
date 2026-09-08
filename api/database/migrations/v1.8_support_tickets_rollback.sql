-- VELORA migration v1.8 rollback — removes Phase 9A support ticket tables.
-- Additive rollback only; no other schema is touched.

DROP TABLE IF EXISTS support_message_translations;
DROP TABLE IF EXISTS support_messages;
DROP TABLE IF EXISTS support_conversations;
