<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateCatalogItemTypeLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'catalog_item_types';
    }

    protected function routeParam(): string
    {
        return 'catalog_item_type';
    }
}
