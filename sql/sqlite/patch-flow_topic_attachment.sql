CREATE TABLE /*_*/flow_topic_attachment (
  fa_id BLOB NOT NULL,
  fa_workflow_id BLOB NOT NULL,
  fa_post_id BLOB DEFAULT NULL,
  fa_user_id BIGINT UNSIGNED NOT NULL,
  fa_name BLOB NOT NULL,
  fa_size INTEGER UNSIGNED NOT NULL,
  fa_mime BLOB NOT NULL,
  fa_sha1 BLOB NOT NULL,
  PRIMARY KEY(fa_id)
);

CREATE INDEX flow_topic_attachment_workflow ON /*_*/flow_topic_attachment (fa_workflow_id, fa_id);
