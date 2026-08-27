CREATE TABLE /*_*/flow_topic_attachment (
  fa_id BINARY(11) NOT NULL,
  fa_workflow_id BINARY(11) NOT NULL,
  fa_post_id BINARY(11) DEFAULT NULL,
  fa_user_id BIGINT UNSIGNED NOT NULL,
  fa_name VARBINARY(255) NOT NULL,
  fa_size INT UNSIGNED NOT NULL,
  fa_mime VARBINARY(255) NOT NULL,
  fa_sha1 VARBINARY(32) NOT NULL,
  INDEX flow_topic_attachment_workflow (fa_workflow_id, fa_id),
  PRIMARY KEY(fa_id)
) /*$wgDBTableOptions*/;
