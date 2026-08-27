CREATE TABLE flow_topic_attachment (
  fa_id TEXT NOT NULL,
  fa_workflow_id TEXT NOT NULL,
  fa_post_id TEXT DEFAULT NULL,
  fa_user_id BIGINT NOT NULL,
  fa_name TEXT NOT NULL,
  fa_size INT NOT NULL,
  fa_mime TEXT NOT NULL,
  fa_sha1 TEXT NOT NULL,
  PRIMARY KEY(fa_id)
);

CREATE INDEX flow_topic_attachment_workflow ON flow_topic_attachment (fa_workflow_id, fa_id);
