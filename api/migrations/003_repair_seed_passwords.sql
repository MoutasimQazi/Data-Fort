-- Repair the development accounts created by the earlier seed migrations.
-- Do not run this on a production database.

SET NAMES utf8mb4;

UPDATE users
SET password_hash = CASE
  WHEN email = 'admin@moveneticsdigital.com'
    THEN '$2b$10$7Bk/l5sK5pPB5U46wFoIS.EqqnhtK6/aNo8COFGaGgHQKkTmImT5u'
  ELSE '$2b$10$iPXaWTMFAgjoKbY13DyRT.SkdEXnGsSaYuCgi8qFGczR78dJhPKGa'
END
WHERE email = 'admin@moveneticsdigital.com'
   OR email IN (
     'priya@moveneticsdigital.com',
     'rahul@moveneticsdigital.com',
     'aisha@moveneticsdigital.com',
     'vikram@moveneticsdigital.com',
     'sneha@moveneticsdigital.com'
   );