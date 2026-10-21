# Variant proto-dto
Fixtures: proto-user@example.com owns `Rent` (01a15ef6-4b76-78a1-8df2-d42da5bcf655, in use by one Subscription: Rent); proto-other@example.com owns `Secret` (01a15ef6-4b7f-7277-a9df-cbb685126559).

## 1. create
`POST /api/v1/proto-dto/expense-categories` (application/json) `{"name":"Food","color":"#ff0000"}`
→ **201** `application/hal+json`
```json
{
    "_links": {
        "self": {
            "href": "/api/v1/proto-dto/expense-categories/01a15ef6-4b95-786a-a17b-f9100ff49f10"
        }
    },
    "id": "01a15ef6-4b95-786a-a17b-f9100ff49f10",
    "name": "Food",
    "color": "#ff0000",
    "createdAt": "2026-10-21T14:56:01+00:00",
    "updatedAt": "2026-10-21T14:56:01+00:00"
}
```

## 2. create duplicate name
`POST /api/v1/proto-dto/expense-categories` (application/json) `{"name":"Rent","color":"#ff0000"}`
→ **422** `application/problem+json`
```json
{
    "status": 422,
    "violations": [
        {
            "propertyPath": "name",
            "message": "You already have a category with name \"Rent\".",
            "code": null
        }
    ],
    "detail": "name: You already have a category with name \"Rent\".",
    "type": "/validation_errors/0000000000000a1e0000000000000000",
    "title": "An error occurred"
}
```

## 3. create invalid fields
`POST /api/v1/proto-dto/expense-categories` (application/json) `{"name":"F","color":"red"}`
→ **422** `application/problem+json`
```json
{
    "status": 422,
    "violations": [
        {
            "propertyPath": "name",
            "message": "This value is too short. It should have 2 characters or more.",
            "code": "9ff3fdc4-b214-49db-8718-39c315e33d45"
        },
        {
            "propertyPath": "color",
            "message": "Please provide a color tag in hex format, e.g. #577399.",
            "code": "de1e3db3-5ed4-4941-aae4-59f3667cc3a3"
        }
    ],
    "detail": "name: This value is too short. It should have 2 characters or more.\ncolor: Please provide a color tag in hex format, e.g. #577399.",
    "type": "/validation_errors/0=9ff3fdc4-b214-49db-8718-39c315e33d45;1=de1e3db3-5ed4-4941-aae4-59f3667cc3a3",
    "title": "An error occurred"
}
```

## 4. create malformed JSON
`POST /api/v1/proto-dto/expense-categories` (application/json) `{"name":`
→ **400** `application/problem+json`
```json
{
    "title": "An error occurred",
    "detail": "Syntax error",
    "status": 400,
    "type": "/errors/400"
}
```

## 5. create wrong type
`POST /api/v1/proto-dto/expense-categories` (application/json) `{"name":123,"color":"#000000"}`
→ **422** `application/problem+json`
```json
{
    "status": 422,
    "violations": [
        {
            "propertyPath": "name",
            "message": "This value should be of type null|string.",
            "code": "ba785a8c-82cb-4283-967c-3cf342181b40"
        }
    ],
    "detail": "name: This value should be of type null|string.",
    "type": "/validation_errors/ba785a8c-82cb-4283-967c-3cf342181b40",
    "title": "An error occurred"
}
```

## 6. create with read-only/unknown fields
`POST /api/v1/proto-dto/expense-categories` (application/json) `{"name":"Travel","color":"#123456","id":"01a15ef6-4b7f-7277-a9df-cbb685126559","createdAt":"2000-01-01T00:00:00+00:00","owner":"x"}`
→ **400** `application/problem+json`
```json
{
    "title": "An error occurred",
    "detail": "Update is not allowed for this operation.",
    "status": 400,
    "type": "/errors/400"
}
```

## 7. create name taken only by another User
`POST /api/v1/proto-dto/expense-categories` (application/json) `{"name":"Secret","color":"#123456"}`
→ **201** `application/hal+json`
```json
{
    "_links": {
        "self": {
            "href": "/api/v1/proto-dto/expense-categories/01a15ef6-4bd8-7a52-807e-e10c0cbd8a76"
        }
    },
    "id": "01a15ef6-4bd8-7a52-807e-e10c0cbd8a76",
    "name": "Secret",
    "color": "#123456",
    "createdAt": "2026-10-21T14:56:02+00:00",
    "updatedAt": "2026-10-21T14:56:02+00:00"
}
```

## 8. list (no Accept: HAL default)
`GET /api/v1/proto-dto/expense-categories`
→ **200** `application/hal+json`
```json
{
    "_links": {
        "self": {
            "href": "/api/v1/proto-dto/expense-categories"
        },
        "item": [
            {
                "href": "/api/v1/proto-dto/expense-categories/01a15ef6-4b95-786a-a17b-f9100ff49f10"
            },
            {
                "href": "/api/v1/proto-dto/expense-categories/01a15ef6-4b76-78a1-8df2-d42da5bcf655"
            },
            {
                "href": "/api/v1/proto-dto/expense-categories/01a15ef6-4bd8-7a52-807e-e10c0cbd8a76"
            }
        ]
    },
    "totalItems": 3,
    "_embedded": {
        "item": [
            {
                "_links": {
                    "self": {
                        "href": "/api/v1/proto-dto/expense-categories/01a15ef6-4b95-786a-a17b-f9100ff49f10"
                    }
                },
                "id": "01a15ef6-4b95-786a-a17b-f9100ff49f10",
                "name": "Food",
                "color": "#ff0000",
                "createdAt": "2026-10-21T14:56:01+00:00",
                "updatedAt": "2026-10-21T14:56:01+00:00"
            },
            {
                "_links": {
                    "self": {
                        "href": "/api/v1/proto-dto/expense-categories/01a15ef6-4b76-78a1-8df2-d42da5bcf655"
                    }
                },
                "id": "01a15ef6-4b76-78a1-8df2-d42da5bcf655",
                "name": "Rent",
                "color": "#111111",
                "createdAt": "2026-10-21T14:56:01+00:00",
                "updatedAt": "2026-10-21T14:56:01+00:00"
            },
            {
                "_links": {
                    "self": {
                        "href": "/api/v1/proto-dto/expense-categories/01a15ef6-4bd8-7a52-807e-e10c0cbd8a76"
                    }
                },
                "id": "01a15ef6-4bd8-7a52-807e-e10c0cbd8a76",
                "name": "Secret",
                "color": "#123456",
                "createdAt": "2026-10-21T14:56:02+00:00",
                "updatedAt": "2026-10-21T14:56:02+00:00"
            }
        ]
    }
}
```

## 9. list as application/json
`GET /api/v1/proto-dto/expense-categories`
→ **200** `application/json`
```json
[
    {
        "id": "01a15ef6-4b95-786a-a17b-f9100ff49f10",
        "name": "Food",
        "color": "#ff0000",
        "createdAt": "2026-10-21T14:56:01+00:00",
        "updatedAt": "2026-10-21T14:56:01+00:00"
    },
    {
        "id": "01a15ef6-4b76-78a1-8df2-d42da5bcf655",
        "name": "Rent",
        "color": "#111111",
        "createdAt": "2026-10-21T14:56:01+00:00",
        "updatedAt": "2026-10-21T14:56:01+00:00"
    },
    {
        "id": "01a15ef6-4bd8-7a52-807e-e10c0cbd8a76",
        "name": "Secret",
        "color": "#123456",
        "createdAt": "2026-10-21T14:56:02+00:00",
        "updatedAt": "2026-10-21T14:56:02+00:00"
    }
]
```

## 10. list as application/ld+json
`GET /api/v1/proto-dto/expense-categories`
→ **200** `application/ld+json`
```json
{
    "@context": "/api/contexts/ProtoDtoExpenseCategory",
    "@id": "/api/v1/proto-dto/expense-categories",
    "@type": "Collection",
    "totalItems": 3,
    "member": [
        {
            "@id": "/api/v1/proto-dto/expense-categories/01a15ef6-4b95-786a-a17b-f9100ff49f10",
            "@type": "ProtoDtoExpenseCategory",
            "id": "01a15ef6-4b95-786a-a17b-f9100ff49f10",
            "name": "Food",
            "color": "#ff0000",
            "createdAt": "2026-10-21T14:56:01+00:00",
            "updatedAt": "2026-10-21T14:56:01+00:00"
        },
        {
            "@id": "/api/v1/proto-dto/expense-categories/01a15ef6-4b76-78a1-8df2-d42da5bcf655",
            "@type": "ProtoDtoExpenseCategory",
            "id": "01a15ef6-4b76-78a1-8df2-d42da5bcf655",
            "name": "Rent",
            "color": "#111111",
            "createdAt": "2026-10-21T14:56:01+00:00",
            "updatedAt": "2026-10-21T14:56:01+00:00"
        },
        {
            "@id": "/api/v1/proto-dto/expense-categories/01a15ef6-4bd8-7a52-807e-e10c0cbd8a76",
            "@type": "ProtoDtoExpenseCategory",
            "id": "01a15ef6-4bd8-7a52-807e-e10c0cbd8a76",
            "name": "Secret",
            "color": "#123456",
            "createdAt": "2026-10-21T14:56:02+00:00",
            "updatedAt": "2026-10-21T14:56:02+00:00"
        }
    ]
}
```

## 11. show own
`GET /api/v1/proto-dto/expense-categories/01a15ef6-4b95-786a-a17b-f9100ff49f10`
→ **200** `application/hal+json`
```json
{
    "_links": {
        "self": {
            "href": "/api/v1/proto-dto/expense-categories/01a15ef6-4b95-786a-a17b-f9100ff49f10"
        }
    },
    "id": "01a15ef6-4b95-786a-a17b-f9100ff49f10",
    "name": "Food",
    "color": "#ff0000",
    "createdAt": "2026-10-21T14:56:01+00:00",
    "updatedAt": "2026-10-21T14:56:01+00:00"
}
```

## 12. show foreign
`GET /api/v1/proto-dto/expense-categories/01a15ef6-4b7f-7277-a9df-cbb685126559`
→ **404** `application/problem+json`
```json
{
    "title": "An error occurred",
    "detail": "Not Found",
    "status": 404,
    "type": "/errors/404"
}
```

## 13. show malformed id
`GET /api/v1/proto-dto/expense-categories/not-a-uuid`
→ **404** `application/problem+json`
```json
{
    "title": "An error occurred",
    "detail": "Invalid uri variables.",
    "status": 404,
    "type": "/errors/404"
}
```

## 14. patch color only
`PATCH /api/v1/proto-dto/expense-categories/01a15ef6-4b95-786a-a17b-f9100ff49f10` (application/merge-patch+json) `{"color":"#00ff00"}`
→ **200** `application/hal+json`
```json
{
    "_links": {
        "self": {
            "href": "/api/v1/proto-dto/expense-categories/01a15ef6-4b95-786a-a17b-f9100ff49f10"
        }
    },
    "id": "01a15ef6-4b95-786a-a17b-f9100ff49f10",
    "name": "Food",
    "color": "#00ff00",
    "createdAt": "2026-10-21T14:56:01+00:00",
    "updatedAt": "2026-10-21T14:56:02+00:00"
}
```

## 15. patch to a taken name
`PATCH /api/v1/proto-dto/expense-categories/01a15ef6-4b95-786a-a17b-f9100ff49f10` (application/merge-patch+json) `{"name":"Rent"}`
→ **422** `application/problem+json`
```json
{
    "status": 422,
    "violations": [
        {
            "propertyPath": "name",
            "message": "You already have a category with name \"Rent\".",
            "code": null
        }
    ],
    "detail": "name: You already have a category with name \"Rent\".",
    "type": "/validation_errors/000000000000057f0000000000000000",
    "title": "An error occurred"
}
```

## 16. patch foreign
`PATCH /api/v1/proto-dto/expense-categories/01a15ef6-4b7f-7277-a9df-cbb685126559` (application/merge-patch+json) `{"name":"Mine now"}`
→ **404** `application/problem+json`
```json
{
    "title": "An error occurred",
    "detail": "Not Found",
    "status": 404,
    "type": "/errors/404"
}
```

## 17. patch with application/json
`PATCH /api/v1/proto-dto/expense-categories/01a15ef6-4b95-786a-a17b-f9100ff49f10` (application/json) `{"color":"#0000ff"}`
→ **415** `application/problem+json`
```json
{
    "title": "An error occurred",
    "detail": "The content-type \"application/json\" is not supported. Supported MIME types are \"application/merge-patch+json\".",
    "status": 415,
    "type": "/errors/415"
}
```

## 18. patch null on required field
`PATCH /api/v1/proto-dto/expense-categories/01a15ef6-4b95-786a-a17b-f9100ff49f10` (application/merge-patch+json) `{"name":null}`
→ **422** `application/problem+json`
```json
{
    "status": 422,
    "violations": [
        {
            "propertyPath": "name",
            "message": "This value should not be blank.",
            "code": "c1051bb4-d103-4f74-8988-acbcafc7fdc3"
        }
    ],
    "detail": "name: This value should not be blank.",
    "type": "/validation_errors/c1051bb4-d103-4f74-8988-acbcafc7fdc3",
    "title": "An error occurred"
}
```

## 19. patch empty object
`PATCH /api/v1/proto-dto/expense-categories/01a15ef6-4b95-786a-a17b-f9100ff49f10` (application/merge-patch+json) `{}`
→ **200** `application/hal+json`
```json
{
    "_links": {
        "self": {
            "href": "/api/v1/proto-dto/expense-categories/01a15ef6-4b95-786a-a17b-f9100ff49f10"
        }
    },
    "id": "01a15ef6-4b95-786a-a17b-f9100ff49f10",
    "name": "Food",
    "color": "#00ff00",
    "createdAt": "2026-10-21T14:56:01+00:00",
    "updatedAt": "2026-10-21T14:56:02+00:00"
}
```

## 20. delete category in use
`DELETE /api/v1/proto-dto/expense-categories/01a15ef6-4b76-78a1-8df2-d42da5bcf655`
→ **409** `application/problem+json`
```json
{
    "title": "Expense category in use",
    "detail": "Category \"Rent\" cannot be deleted because it still has 1 subscription(s) assigned. Reassign them first.",
    "status": 409,
    "type": "/problems/category-in-use"
}
```

## 21. delete foreign
`DELETE /api/v1/proto-dto/expense-categories/01a15ef6-4b7f-7277-a9df-cbb685126559`
→ **404** `application/problem+json`
```json
{
    "title": "An error occurred",
    "detail": "Not Found",
    "status": 404,
    "type": "/errors/404"
}
```

## 22. delete free category
`DELETE /api/v1/proto-dto/expense-categories/01a15ef6-4b95-786a-a17b-f9100ff49f10`
→ **204** ``

## 23. no token
`GET /api/v1/proto-dto/expense-categories`
→ **401** `application/problem+json`
```json
{
    "type": "https://api.paysubscriptions.local/v1/problems/unauthorized",
    "title": "Authentication required",
    "status": 401,
    "detail": "Authentication is required to access this resource.",
    "code": "unauthorized"
}
```

