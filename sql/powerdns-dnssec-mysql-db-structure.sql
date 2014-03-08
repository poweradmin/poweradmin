SET SESSION SQL_MODE='ANSI,ANSI_QUOTES,TRADITIONAL';
CREATE TABLE domainmetadata (
    id        INTEGER AUTO_INCREMENT,
    domain_id INTEGER NOT NULL,
    kind      VARCHAR(16),
    content   TEXT,
    PRIMARY KEY (id),
    INDEX domainmetaidindex ( domain_id )
);

CREATE TABLE Cryptokeys (
    id        INTEGER AUTO_INCREMENT,
    domain_id INTEGER NOT NULL,
    flags     INTEGER NOT NULL,
    active    BOOLEAN,
    content   TEXT,
    PRIMARY KEY(id),
    domainidindex ( domain_id )
);		 

ALTER TABLE records
    , ADD COLUMN "ordername" VARCHAR(255)
    , ADD COLUMN "auth" BOOLEAN
    , ADD INDEX "orderindex" ( ordername )
    , CHANGE COLUMN "type" "type" VARCHAR(10);

CREATE TABLE tsigkeys (
    id          INTEGER auto_increment,
    name        VARCHAR(255), 
    "algorithm" VARCHAR(50),
    secret      VARCHAR(255),
    PRIMARY KEY(id),
    namealgoindex ( name, "algorithm" )
);
