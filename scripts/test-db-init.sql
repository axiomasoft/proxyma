CREATE SCHEMA proxyma_rig;
CREATE TABLE proxyma_rig.marker (value text PRIMARY KEY CHECK (value = 'proxyma_test_v1'));
INSERT INTO proxyma_rig.marker VALUES ('proxyma_test_v1');
