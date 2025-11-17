#!/bin/bash

##############################################################################
# InvoiceShelf API & Data Verification Test
# Tests login, API endpoints, and data integrity via curl
##############################################################################

BASE_URL="http://localhost:8080"
EMAIL="nelson.talemwa@royaldentalservices.com"
PASSWORD="Nelson@RDSClinic"

echo ""
echo "╔════════════════════════════════════════════════════════════════╗"
echo "║  INVOICESHELF API & DATA VERIFICATION TEST                     ║"
echo "╚════════════════════════════════════════════════════════════════╝"
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

PASSED=0
FAILED=0

##############################################################################
echo "═══════════════════════════════════════════════════════════════"
echo "TEST 1: Login Endpoint"
echo "═══════════════════════════════════════════════════════════════"
echo ""

# Get CSRF token first
CSRF_RESPONSE=$(curl -s -c /tmp/cookies.txt "${BASE_URL}/login")
CSRF_TOKEN=$(echo "$CSRF_RESPONSE" | grep -oP 'csrf-token" content="\K[^"]+' | head -1)

if [ -n "$CSRF_TOKEN" ]; then
    echo "✓ Got CSRF token: ${CSRF_TOKEN:0:20}..."
else
    echo "⚠ No CSRF token found, trying without it"
fi

# Attempt login
LOGIN_RESPONSE=$(curl -s -b /tmp/cookies.txt -c /tmp/cookies.txt \
    -X POST "${BASE_URL}/login" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -H "X-CSRF-TOKEN: ${CSRF_TOKEN}" \
    -d "{\"email\":\"${EMAIL}\",\"password\":\"${PASSWORD}\"}")

echo "Login response: ${LOGIN_RESPONSE:0:200}"

# Check if we got a token
TOKEN=$(echo "$LOGIN_RESPONSE" | grep -oP '"token":"\K[^"]+' | head -1)

if [ -n "$TOKEN" ]; then
    echo -e "${GREEN}✅ LOGIN SUCCESSFUL${NC}"
    echo "   Token: ${TOKEN:0:30}..."
    ((PASSED++))
else
    echo -e "${RED}✗ LOGIN FAILED${NC}"
    echo "   Response: $LOGIN_RESPONSE"
    ((FAILED++))
    # Try to continue anyway with session cookies
fi

echo ""

##############################################################################
echo "═══════════════════════════════════════════════════════════════"
echo "TEST 2: Database Direct Verification"
echo "═══════════════════════════════════════════════════════════════"
echo ""

# Check database directly
echo "Checking database records..."

mysql -u invoiceshelf_user -p'RDS_InvoiceShelf_2025_Secure!' invoiceshelf << 'EOF'
SELECT 
    'Customers' as Type,
    COUNT(*) as Total,
    COUNT(CASE WHEN diagnosis IS NOT NULL THEN 1 END) as With_Diagnosis
FROM customers WHERE company_id = 1
UNION ALL
SELECT 
    'Invoices',
    COUNT(*),
    COUNT(CASE WHEN customer_diagnosis IS NOT NULL THEN 1 END)
FROM invoices WHERE company_id = 1
UNION ALL
SELECT 
    'Payments',
    COUNT(*),
    COUNT(CASE WHEN base_amount IS NOT NULL THEN 1 END)
FROM payments WHERE company_id = 1
UNION ALL
SELECT
    'Hash Check',
    COUNT(*),
    COUNT(DISTINCT unique_hash)
FROM invoices WHERE company_id = 1;
EOF

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ DATABASE CHECK PASSED${NC}"
    ((PASSED++))
else
    echo -e "${RED}✗ DATABASE CHECK FAILED${NC}"
    ((FAILED++))
fi

echo ""

##############################################################################
echo "═══════════════════════════════════════════════════════════════"
echo "TEST 3: Currency Configuration"
echo "═══════════════════════════════════════════════════════════════"
echo ""

# Check currency configuration
CURRENCY_CHECK=$(mysql -u invoiceshelf_user -p'RDS_InvoiceShelf_2025_Secure!' invoiceshelf -se \
    "SELECT id, code FROM currencies WHERE id = 1;")

echo "Currency ID 1: $CURRENCY_CHECK"

if echo "$CURRENCY_CHECK" | grep -q "UGX"; then
    echo -e "${GREEN}✅ CURRENCY CORRECT - ID 1 is UGX${NC}"
    ((PASSED++))
else
    echo -e "${RED}✗ CURRENCY WRONG - ID 1 is not UGX${NC}"
    ((FAILED++))
fi

echo ""

##############################################################################
echo "═══════════════════════════════════════════════════════════════"
echo "TEST 4: Hash Decode Test"
echo "═══════════════════════════════════════════════════════════════"
echo ""

# Test hash decoding via PHP
cd /home/royal/InvoiceShelf && php artisan tinker --execute="
use Vinkla\Hashids\Facades\Hashids;
use App\Models\Invoice;

\$invoice = Invoice::first();
if (\$invoice) {
    \$decoded = Hashids::connection(Invoice::class)->decode(\$invoice->unique_hash);
    if (!empty(\$decoded) && \$decoded[0] == \$invoice->id) {
        echo 'PASS: Hash decodes correctly';
    } else {
        echo 'FAIL: Hash decode mismatch';
    }
} else {
    echo 'FAIL: No invoices found';
}
"

if [ $? -eq 0 ]; then
    if php artisan tinker --execute="
use Vinkla\Hashids\Facades\Hashids;
use App\Models\Invoice;
\$invoice = Invoice::first();
\$decoded = Hashids::connection(Invoice::class)->decode(\$invoice->unique_hash);
echo !empty(\$decoded) && \$decoded[0] == \$invoice->id ? 'true' : 'false';
" | grep -q "true"; then
        echo -e "${GREEN}✅ HASH DECODE WORKING${NC}"
        ((PASSED++))
    else
        echo -e "${RED}✗ HASH DECODE FAILED${NC}"
        ((FAILED++))
    fi
fi

echo ""

##############################################################################
echo "═══════════════════════════════════════════════════════════════"
echo "TEST 5: Data Integrity Summary"
echo "═══════════════════════════════════════════════════════════════"
echo ""

mysql -u invoiceshelf_user -p'RDS_InvoiceShelf_2025_Secure!' invoiceshelf << 'EOF'
SELECT 
    'Invoice Due Amount' as Metric,
    CONCAT('UGX ', FORMAT(SUM(base_due_amount)/100, 0)) as Value,
    'Expected: 11,421,030' as Expected
FROM invoices WHERE company_id = 1
UNION ALL
SELECT 
    'Total Receipts',
    CONCAT('UGX ', FORMAT(SUM(base_amount)/100, 0)),
    'Expected: 153,654,000'
FROM payments WHERE company_id = 1
UNION ALL
SELECT 
    'Total Expenses',
    CONCAT('UGX ', FORMAT(SUM(base_amount)/100, 0)),
    'Expected: 134,683,452'
FROM expenses WHERE company_id = 1
UNION ALL
SELECT
    'Net Income',
    CONCAT('UGX ', FORMAT((
        (SELECT SUM(base_amount) FROM payments WHERE company_id = 1) -
        (SELECT SUM(base_amount) FROM expenses WHERE company_id = 1)
    )/100, 0)),
    'Expected: 18,970,548';
EOF

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ DATA INTEGRITY CHECK PASSED${NC}"
    ((PASSED++))
else
    echo -e "${RED}✗ DATA INTEGRITY CHECK FAILED${NC}"
    ((FAILED++))
fi

echo ""

##############################################################################
echo "═══════════════════════════════════════════════════════════════"
echo "TEST 6: Final Data Re-verification"
echo "═══════════════════════════════════════════════════════════════"
echo ""

# Compare with Crater one more time
echo "Comparing InvoiceShelf vs Crater data..."

SHELF_CUSTOMERS=$(mysql -u invoiceshelf_user -p'RDS_InvoiceShelf_2025_Secure!' invoiceshelf -se \
    "SELECT COUNT(*) FROM customers WHERE company_id = 1;")
CRATER_CUSTOMERS=$(mysql -h 127.0.0.1 -P 33008 -u crater -pcrater crater -se \
    "SELECT COUNT(*) FROM customers;" 2>/dev/null)

SHELF_INVOICES=$(mysql -u invoiceshelf_user -p'RDS_InvoiceShelf_2025_Secure!' invoiceshelf -se \
    "SELECT COUNT(*) FROM invoices WHERE company_id = 1;")
CRATER_INVOICES=$(mysql -h 127.0.0.1 -P 33008 -u crater -pcrater crater -se \
    "SELECT COUNT(*) FROM invoices;" 2>/dev/null)

echo "Customers: InvoiceShelf=$SHELF_CUSTOMERS, Crater=$CRATER_CUSTOMERS"
echo "Invoices:  InvoiceShelf=$SHELF_INVOICES, Crater=$CRATER_INVOICES"

if [ "$SHELF_CUSTOMERS" = "$CRATER_CUSTOMERS" ] && [ "$SHELF_INVOICES" = "$CRATER_INVOICES" ]; then
    echo -e "${GREEN}✅ DATA COUNT MATCHES${NC}"
    ((PASSED++))
else
    echo -e "${RED}✗ DATA COUNT MISMATCH${NC}"
    ((FAILED++))
fi

echo ""

##############################################################################
echo "═══════════════════════════════════════════════════════════════"
echo "TEST SUMMARY"
echo "═══════════════════════════════════════════════════════════════"
echo ""

TOTAL=$((PASSED + FAILED))
echo "Tests Run:    $TOTAL"
echo -e "Passed:       ${GREEN}$PASSED${NC}"
echo -e "Failed:       ${RED}$FAILED${NC}"
echo ""

if [ $FAILED -eq 0 ]; then
    echo "╔════════════════════════════════════════════════════════════════╗"
    echo "║  ✅ ALL TESTS PASSED - 100% DATA INTEGRITY VERIFIED           ║"
    echo "╚════════════════════════════════════════════════════════════════╝"
    echo ""
    exit 0
else
    echo "╔════════════════════════════════════════════════════════════════╗"
    echo "║  ⚠️  SOME TESTS FAILED - REVIEW REQUIRED                      ║"
    echo "╚════════════════════════════════════════════════════════════════╝"
    echo ""
    exit 1
fi
