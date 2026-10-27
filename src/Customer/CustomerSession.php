<?php

namespace App\Customer;

use App\Repository\CatalogueRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Remembers the signed-in customer in the Symfony session.
 *
 * Demo sign-in, two ways:
 *  - pick a demo customer from a list (to try the app quickly)
 *  - email address + one of your order numbers (like a guest order lookup)
 * Only the customer ID is stored in the session; details are always reloaded from the database.
 */
final class CustomerSession
{
    private const KEY = 'customer_id';

    public function __construct(private readonly CatalogueRepository $catalogue)
    {
    }

    /**
     * @return array{id: int, name: string, email: string, city: string}|null
     */
    public function current(Request $request): ?array
    {
        if (!$request->hasSession()) {
            return null;
        }

        $id = $request->getSession()->get(self::KEY);

        return \is_int($id) ? $this->catalogue->findCustomer($id) : null;
    }

    /**
     * @return array{id: int, name: string, email: string, city: string}|null
     */
    public function signInAsDemoCustomer(Request $request, int $customerId): ?array
    {
        $customer = $this->catalogue->findCustomer($customerId);

        return $customer === null ? null : $this->remember($request, $customer);
    }

    /**
     * @return array{id: int, name: string, email: string, city: string}|null null when email and order number do not match
     */
    public function signInWithOrder(Request $request, string $email, string $orderNumber): ?array
    {
        $customer = $this->catalogue->findCustomerByEmailAndOrder($email, $orderNumber);

        return $customer === null ? null : $this->remember($request, $customer);
    }

    public function signOut(Request $request): void
    {
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }
    }

    /**
     * @param array{id: int, name: string, email: string, city: string} $customer
     * @return array{id: int, name: string, email: string, city: string}
     */
    private function remember(Request $request, array $customer): array
    {
        $session = $request->getSession();
        // New session ID on sign-in, so an old (possibly stolen) session ID becomes useless
        $session->migrate(true);
        $session->set(self::KEY, $customer['id']);

        return $customer;
    }
}
