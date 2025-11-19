-- Project & calendar
INSERT INTO projects (name, start_date, timezone, calendar_id)
VALUES ('Sample Tower – Core Programme', '2025-08-18', 'Europe/London', 1);

INSERT INTO apartments (project_id, block, floor, unit, type) VALUES (1, 'A', '1', '01', 'Type A');

INSERT INTO contractors (name, colour) VALUES
('Panacea', '#60a5fa'),
('GPL', '#34d399'),
('TECL', '#f59e0b'),
('Edencroft', '#f87171'),
('Armstrong', '#a78bfa'),
('Checks', '#93c5fd'),
('Active Flooring', '#fbbf24'),
('Smiths', '#6ee7b7');

INSERT INTO tasks (project_id, apartment_id, name, contractor_id, operatives, duration_days, zone) VALUES
(1,1,'BWH to structural walls',(SELECT id FROM contractors WHERE name='Panacea'),2,3,'Core'),
(1,1,'SVP/RWP install',(SELECT id FROM contractors WHERE name='GPL'),2,5,'Wetrooms'),
(1,1,'Fire stop to SVP/RWP',(SELECT id FROM contractors WHERE name='TECL'),2,5,'Wetrooms'),
(1,1,'Structural walls 1st fix',(SELECT id FROM contractors WHERE name='Edencroft'),4,5,'Core'),
(1,1,'1st side structural walls & partitions',(SELECT id FROM contractors WHERE name='Panacea'),4,5,'Core'),
(1,1,'1st fix wire',(SELECT id FROM contractors WHERE name='Edencroft'),2,5,'Core'),
(1,1,'1st fix plumbing (excl. kitchen waste)',(SELECT id FROM contractors WHERE name='GPL'),2,3,'Wetrooms'),
(1,1,'2nd side board structural walls',(SELECT id FROM contractors WHERE name='Panacea'),4,3,'Core'),
(1,1,'Vents/ducts 1st fix',(SELECT id FROM contractors WHERE name='GPL'),2,10,'Ceilings'),
(1,1,'MF ceilings',(SELECT id FROM contractors WHERE name='Panacea'),2,5,'Ceilings'),
(1,1,'Sprinkler 1st fix',(SELECT id FROM contractors WHERE name='Armstrong'),2,3,'Ceilings'),
(1,1,'Ceiling Void Closure Checks',(SELECT id FROM contractors WHERE name='Checks'),1,1,'Ceilings'),
(1,1,'Sprinkler Test',(SELECT id FROM contractors WHERE name='Armstrong'),2,1,'Ceilings'),
(1,1,'Board ceilings',(SELECT id FROM contractors WHERE name='Panacea'),2,3,'Ceilings'),
(1,1,'Spray plaster & sand',(SELECT id FROM contractors WHERE name='Panacea'),6,4,'Finishes'),
(1,1,'Latex floors – Visit 1',(SELECT id FROM contractors WHERE name='Active Flooring'),1,2,'Floors'),
(1,1,'Mist coat',(SELECT id FROM contractors WHERE name='Smiths'),1,2,'Finishes'),
(1,1,'Latex floors – Visit 2',(SELECT id FROM contractors WHERE name='Active Flooring'),1,2,'Floors');

INSERT INTO dependencies (task_id, predecessor_id, type, lag_days) VALUES
(2,1,'FS',0),
(3,2,'SS',0),
(4,1,'FS',0),
(5,4,'FS',0),
(6,5,'SS',1),
(7,5,'SS',1),
(8,6,'FS',0),
(8,7,'FS',0),
(9,5,'SS',0),
(10,9,'FS',0),
(11,10,'FS',0),
(12,11,'FS',0),
(13,12,'FS',0),
(14,13,'FS',0),
(15,8,'FS',0),
(15,14,'FS',0),
(16,15,'FS',1),
(17,15,'FS',2),
(18,17,'FS',0);
