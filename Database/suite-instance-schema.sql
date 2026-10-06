-- Apply only to a new isolated Suite-managed fixture database.
-- Provision exactly one immutable binding row separately; never infer it from requests.
CREATE TABLE suite_instance_binding (
    id INT NOT NULL PRIMARY KEY,
    instance_id BIGINT NOT NULL,
    organization_id BIGINT NOT NULL,
    project_id BIGINT NOT NULL,
    local_project_id INT NOT NULL,
    CONSTRAINT suite_single_binding CHECK (id = 1),
    CONSTRAINT suite_bound_project FOREIGN KEY (local_project_id) REFERENCES projects(id)
) ENGINE=InnoDB;

CREATE TABLE suite_user_map (
    suite_user_id BIGINT NOT NULL PRIMARY KEY,
    local_user_id INT NOT NULL UNIQUE,
    CONSTRAINT suite_local_user FOREIGN KEY (local_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
