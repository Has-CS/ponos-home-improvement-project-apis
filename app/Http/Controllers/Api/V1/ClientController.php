<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\IndexClientRequest;
use App\Http\Requests\Api\V1\Client\StoreClientRequest;
use App\Http\Requests\Api\V1\Client\UpdateClientRequest;
use App\Http\Resources\Api\V1\ClientDetailResource;
use App\Http\Resources\Api\V1\ClientListResource;
use App\Models\Client;
use App\Services\Client\ClientService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ClientController extends Controller
{
    public function __construct(private readonly ClientService $clients) {}

    /** GET /api/v1/clients — paginated list (search, sort). */
    public function index(IndexClientRequest $request): JsonResponse
    {
        $page = $this->clients->paginate($request->validated());

        return ApiResponse::success([
            'items' => ClientListResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
        ], 'OK');
    }

    /** POST /api/v1/clients — create (Admin, manage_clients). */
    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = $this->clients->create($request->validated(), $request->user()->id);
        return ApiResponse::success(new ClientDetailResource($client), 'Client created successfully.', 201);
    }

    /** GET /api/v1/clients/{client} — full detail. */
    public function show(Client $client): JsonResponse
    {
        return ApiResponse::success(
            new ClientDetailResource($this->clients->findDetailed($client)),
            'OK'
        );
    }

    /** PATCH /api/v1/clients/{client} — partial update (Admin, manage_clients). */
    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        $client = $this->clients->update($client, $request->validated());
        return ApiResponse::success(new ClientDetailResource($client), 'Client updated successfully.');
    }

    /** DELETE /api/v1/clients/{client} — soft delete (Admin, manage_clients). */
    public function destroy(Client $client): JsonResponse
    {
        try {
            $this->clients->delete($client);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(null, 'Client deleted successfully.');
    }
}
