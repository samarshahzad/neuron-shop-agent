<?php

namespace App\Controller;

use App\Customer\CustomerSession;
use App\Recommendation\RecommendationEngine;
use App\Repository\CatalogueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON API for the product page, the customer sign-in and the recommendations.
 */
final class StoreController extends AbstractController
{
    public function __construct(
        private readonly CatalogueRepository $catalogue,
        private readonly RecommendationEngine $recommendations,
        private readonly CustomerSession $customerSession,
    ) {
    }

    #[Route('/api/products', name: 'api_products', methods: ['GET'])]
    public function products(Request $request): JsonResponse
    {
        $category = $request->query->get('category');

        return $this->json([
            'categories' => $this->catalogue->categories(),
            'products' => $this->catalogue->allProducts(\is_string($category) ? $category : null),
            'similarity' => $this->recommendations->activeMethod(),
        ]);
    }

    #[Route('/api/products/{sku}/similar', name: 'api_products_similar', methods: ['GET'], requirements: ['sku' => '[A-Za-z0-9-]{1,20}'])]
    public function similar(string $sku): JsonResponse
    {
        $product = $this->catalogue->findProductBySku($sku);
        if ($product === null) {
            return $this->json(['error' => 'Product not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['product' => $product] + $this->recommendations->similarTo($product['id']));
    }

    #[Route('/api/customers', name: 'api_customers', methods: ['GET'])]
    public function customers(): JsonResponse
    {
        // Demo only: a real shop never lists its customers publicly
        return $this->json(['customers' => $this->catalogue->customers()]);
    }

    #[Route('/api/session', name: 'api_session', methods: ['GET'])]
    public function session(Request $request): JsonResponse
    {
        return $this->json(['customer' => $this->customerSession->current($request)]);
    }

    #[Route('/api/session', name: 'api_session_sign_in', methods: ['POST'])]
    public function signIn(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid request.'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['customerId'])) {
            $customer = \is_int($data['customerId'])
                ? $this->customerSession->signInAsDemoCustomer($request, $data['customerId'])
                : null;

            return $customer === null
                ? $this->json(['error' => 'Customer not found.'], Response::HTTP_NOT_FOUND)
                : $this->json(['customer' => $customer]);
        }

        $email = trim((string) ($data['email'] ?? ''));
        $orderNumber = trim((string) ($data['orderNumber'] ?? ''));
        if ($email === '' || $orderNumber === '' || mb_strlen($email) > 180 || mb_strlen($orderNumber) > 20) {
            return $this->json(['error' => 'Please enter your email address and an order number.'], Response::HTTP_BAD_REQUEST);
        }

        $customer = $this->customerSession->signInWithOrder($request, $email, $orderNumber);

        // Same message for "unknown email" and "wrong order number": no hints for attackers
        return $customer === null
            ? $this->json(['error' => 'Email address and order number do not match.'], Response::HTTP_UNAUTHORIZED)
            : $this->json(['customer' => $customer]);
    }

    #[Route('/api/session', name: 'api_session_sign_out', methods: ['DELETE'])]
    public function signOut(Request $request): JsonResponse
    {
        $this->customerSession->signOut($request);

        return $this->json(['customer' => null]);
    }

    #[Route('/api/me/orders', name: 'api_my_orders', methods: ['GET'])]
    public function myOrders(Request $request): JsonResponse
    {
        $customer = $this->customerSession->current($request);
        if ($customer === null) {
            return $this->json(['error' => 'Not signed in.'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['orders' => $this->catalogue->ordersForCustomer($customer['id'])]);
    }

    #[Route('/api/me/recommendations', name: 'api_my_recommendations', methods: ['GET'])]
    public function myRecommendations(Request $request): JsonResponse
    {
        $customer = $this->customerSession->current($request);
        if ($customer === null) {
            return $this->json(['error' => 'Not signed in.'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($this->recommendations->forCustomer($customer['id']));
    }
}
