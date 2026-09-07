-- Apply once using an account with ALTER permission, before enabling the command.
-- Do not run the ADD again if the column already exists.
ALTER TABLE prism_envios ADD COLUMN processing_checkpoint LONGTEXT NULL;
ALTER TABLE prism_envios_log MODIFY COLUMN step VARCHAR(80) NOT NULL;
