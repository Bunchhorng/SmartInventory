USE smartstock;
INSERT INTO roles (code,name) VALUES ('owner','ម្ចាស់អាជីវកម្ម'),('staff','បុគ្គលិក');
INSERT INTO permissions (code,description) VALUES
('products.view','មើលទំនិញ'),('products.manage','បង្កើត និងកែទំនិញ'),
('pricing.override','កែតម្លៃលក់'),('pricing.below_cost.approve','អនុម័តលក់ក្រោមថ្លៃដើម'),
('stock.view','មើលស្តុក'),('stock.receive','ទទួលស្តុក'),('stock.adjust.request','ស្នើកែស្តុក'),('stock.adjust.approve','អនុម័តកែស្តុក'),
('sales.create','បង្កើតការលក់'),('sales.confirm','បញ្ជាក់ការលក់'),('sales.void','បោះបង់ការលក់'),
('payments.record','កត់ត្រាការទូទាត់'),('credit.manage','គ្រប់គ្រងបំណុល'),
('expenses.create','កត់ត្រាចំណាយ'),('expenses.approve','អនុម័តចំណាយ'),
('fuel.shift.open','បើកវេនប្រេង'),('fuel.meter.enter','បញ្ចូល Meter'),('fuel.shift.submit','ដាក់វេនឱ្យពិនិត្យ'),('fuel.shift.approve','អនុម័តបិទវេន'),
('fuel.variance.approve','អនុម័តភាពខុសគ្នា'),('fuel.price.manage','កែតម្លៃប្រេង'),
('reports.view','មើលរបាយការណ៍'),('reports.profit','មើលប្រាក់ចំណេញ'),
('users.manage','គ្រប់គ្រងអ្នកប្រើ'),('settings.manage','គ្រប់គ្រងប្រព័ន្ធ'),('audit.view','មើល Audit Log');
INSERT INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='owner';
INSERT INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code IN ('products.view','stock.view','stock.receive','stock.adjust.request','sales.create','sales.confirm','payments.record','expenses.create','fuel.shift.open','fuel.meter.enter','fuel.shift.submit') WHERE r.code='staff';
INSERT INTO units (code,name,precision_digits) VALUES ('pcs','Piece',0),('bottle','Bottle',0),('case','Case',0),('bag','Bag',0),('kg','Kilogram',3),('liter','Liter',3);
INSERT INTO payment_methods (code,name) VALUES ('cash','Cash KHR/USD'),('bank','Bank Transfer/KHQR');
INSERT INTO stock_locations (code,name,type) VALUES ('RETAIL','Store Shelves','retail'),('MAIN','Main Storage','storage'),('AGRI','Agricultural Storage','agriculture'),('DAMAGED','Damaged Stock','damage');
INSERT INTO fuel_pumps (pump_code,name) VALUES ('PUMP-001','Main Fuel Pump');
INSERT INTO system_settings (setting_key,setting_value) VALUES ('costing_method','moving_weighted_average'),('fuel_shift_policy','owner_review'),('business_mode','single_owner_single_location');
-- Do not seed real staff passwords, fuel tanks or nozzles before validating physical equipment.
-- CREATE OWNER ACCOUNT VIA password_hash() WITH PASSWORD_ARGON2ID OR PASSWORD_BCRYPT IN PHP.
