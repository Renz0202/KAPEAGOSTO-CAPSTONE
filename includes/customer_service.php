<?php

function customer_find_or_create(PDO $pdo, string $name, string $contact = '', string $email = ''): ?int
{
    $name = trim($name) !== '' ? trim($name) : 'Walk-in';
    $contact = trim($contact);
    $email = strtolower(trim($email));

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid customer email address.');
    }

    if (strcasecmp($name, 'Walk-in') === 0 && $contact === '' && $email === '') {
        return null;
    }

    $matches = [];
    $params = [];
    if ($email !== '') {
        $matches[] = 'LOWER(email) = ?';
        $params[] = $email;
    }
    if ($contact !== '') {
        $matches[] = 'contact = ?';
        $params[] = $contact;
    }

    if ($matches) {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE ' . implode(' OR ', $matches) . ' ORDER BY id ASC LIMIT 1');
        $stmt->execute($params);
        $customerId = (int) $stmt->fetchColumn();
        if ($customerId > 0) {
            $stmt = $pdo->prepare('UPDATE customers SET name = ?, contact = NULLIF(?, \'\'), email = NULLIF(?, \'\') WHERE id = ?');
            $stmt->execute([$name, $contact, $email, $customerId]);
            return $customerId;
        }
    } else {
        // The POS only requires a customer name. Reuse the same exact name so
        // repeat visits appear under one customer history instead of creating
        // a duplicate customer record for every order.
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) ORDER BY id ASC LIMIT 1');
        $stmt->execute([$name]);
        $customerId = (int) $stmt->fetchColumn();
        if ($customerId > 0) {
            return $customerId;
        }
    }

    $code = 'CUS-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $stmt = $pdo->prepare('INSERT INTO customers (customer_code, name, contact, email) VALUES (?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'))');
    $stmt->execute([$code, $name, $contact, $email]);
    return (int) $pdo->lastInsertId();
}
