-- ============================================================================
-- Royal Dental Services - Database Initialization Script
-- 
-- This script runs ONCE when the MySQL container is first created
-- It sets up the database with proper character sets and collation
-- ============================================================================

-- The database is already created by environment variables, but we can set defaults here
ALTER DATABASE invoiceshelf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create any additional databases or configurations if needed
-- This file runs before Laravel migrations

-- Log initialization
SELECT 'Database initialized for InvoiceShelf - Royal Dental Services' AS status;
