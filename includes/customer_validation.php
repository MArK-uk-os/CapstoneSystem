<?php

const CUSTOMER_SUFFIXES = ['', 'Jr', 'Sr', 'II', 'III', 'IV', 'V'];

function customerNameValid(string $value): bool
{
    return (bool) preg_match("/^[A-Za-z][A-Za-z '\-]{0,49}$/", $value);
}

function customerMiddleInitialValid(string $value): bool
{
    return (bool) preg_match('/^[A-Za-z]\.?$/', $value);
}

function normalizeContactNumber(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') return '';
    if (strlen($digits) === 12 && str_starts_with($digits, '639')) return '0' . substr($digits, 2);
    if (strlen($digits) === 10 && str_starts_with($digits, '9')) return '0' . $digits;
    return $digits;
}

function validPhMobile(string $contact): bool
{
    return (bool) preg_match('/^09\d{9}$/', $contact);
}

function fullNameFromParts(string $first, string $middle, string $last, string $suffix): string
{
    $name = trim($first);
    if ($middle !== '') $name .= ' ' . strtoupper($middle) . '.';
    if (trim($last) !== '') $name .= ' ' . trim($last);
    if ($suffix !== '') $name .= ' ' . $suffix . '.';
    return trim(preg_replace('/\s+/', ' ', $name));
}

function findDuplicateCustomer(PDO $pdo, string $field, string $value, ?int $excludeId = null): ?array
{
    if (!in_array($field, ['contact_number', 'email'], true) || $value === '') return null;
    $sql = "SELECT id, account_number, full_name FROM customers WHERE LOWER($field) = LOWER(?)";
    $params = [$value];
    if ($excludeId !== null) { $sql .= ' AND id <> ?'; $params[] = $excludeId; }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function validateCustomer(PDO $pdo, array $raw, array $opts = []): array
{
    $excludeId = $opts['exclude_id'] ?? null;
    $contactRequired = $opts['contact_required'] ?? false;
    $emailRequired = $opts['email_required'] ?? false;

    $first = trim($raw['first_name'] ?? '');
    $middleRaw = trim($raw['middle_initial'] ?? '');
    $last = trim($raw['last_name'] ?? '');
    $suffix = $raw['suffix'] ?? '';
    $contact = normalizeContactNumber(trim($raw['contact_number'] ?? ''));
    $email = trim($raw['email'] ?? '');

    $errors = [];
    if ($first === '') $errors[] = 'First name is required.';
    elseif (!customerNameValid($first)) $errors[] = 'First name may only contain letters, spaces, apostrophes, and hyphens.';
    if ($last === '') $errors[] = 'Last name is required.';
    elseif (!customerNameValid($last)) $errors[] = 'Last name may only contain letters, spaces, apostrophes, and hyphens.';
    if ($middleRaw !== '' && !customerMiddleInitialValid($middleRaw)) $errors[] = 'Middle initial must be a single letter, with an optional period.';
    if ($suffix !== '' && !in_array($suffix, CUSTOMER_SUFFIXES, true)) $errors[] = 'Select a valid suffix.';
    if ($contact === '' && $contactRequired) $errors[] = 'Contact number is required.';
    if ($contact !== '' && !validPhMobile($contact)) $errors[] = 'Contact number must be a Philippine mobile number in 09XXXXXXXXX format.';
    if ($email === '' && $emailRequired) $errors[] = 'Email address is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is invalid.';

    if (!$errors) {
        if ($contact !== '' && ($dup = findDuplicateCustomer($pdo, 'contact_number', $contact, $excludeId))) {
            $errors[] = 'Contact number ' . $contact . ' is already used by customer ' . $dup['account_number'] . ' (' . $dup['full_name'] . ').';
        }
        if ($email !== '' && ($dup = findDuplicateCustomer($pdo, 'email', $email, $excludeId))) {
            $errors[] = 'Email address ' . $email . ' is already used by customer ' . $dup['account_number'] . ' (' . $dup['full_name'] . ').';
        }
    }

    $middle = $middleRaw === '' ? null : strtoupper(rtrim($middleRaw, '.'));
    return [
        'errors' => $errors,
        'values' => [
            'first_name' => $first,
            'middle_initial' => $middle,
            'last_name' => $last,
            'suffix' => $suffix,
            'contact_number' => $contact,
            'email' => $email,
            'full_name' => fullNameFromParts($first, $middle ?? '', $last, $suffix),
        ],
    ];
}

function customerWriteError(PDOException $exception): string
{
    $message = (string) $exception->getMessage();
    if (str_contains($message, 'uq_customers_contact')) return 'Contact number is already used by another customer.';
    if (str_contains($message, 'uq_customers_email')) return 'Email address is already used by another customer.';
    return $message;
}
