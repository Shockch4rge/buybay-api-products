<?php

namespace App\Http\Controllers;

use App\Jobs\FetchReviewsJob;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use Aws\S3\S3Client;
use Database\Seeders\ProductCategorySeeder;
use Database\Seeders\ProductImageSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function index()
    {
        return response([
            "message" => "Success",
            "products" => Product::with(["images", "categories"])->get(),
        ]);
    }

    public function reset()
    {
        Product::query()->delete();
        ProductCategory::query()->delete();
        ProductImage::query()->delete();

        (new ProductSeeder())->run();
        (new ProductCategorySeeder())->run();
        (new ProductImageSeeder())->run();

        Storage::deleteDirectory("products");
        return response([
            "message" => "Success",
        ]);
    }

    public function productsByIds(Request $request)
    {
        $ids = $request->ids;
        $products = Product::query()->whereIn("id", $ids)->with(["images", "categories"])->get();

        return response([
            "products" => $products,
        ]);
    }

    public function store(Request $request)
    {
        $validation = Validator::make($request->all(), [
            "seller_id" => "required",
            "name" => "required|string",
            "description" => "required|string",
            "price" => "required|numeric",
            "quantity" => "required|numeric",
            "images" => "required",
            "images.*" => "image|mimes:jpeg,png,jpg,gif,svg|max:2048",
            "categories" => "array",
            "categories.*" => "string|distinct",
        ]);

        if ($validation->fails()) {
            return response([
                "message" => "Validation failed",
                "errors" => $validation->errors(),
            ], 400);
        }

        $product = Product::query()->create([
            "seller_id" => $request->seller_id,
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'quantity' => $request->quantity,
        ]);

        if ($request->hasFile("images")) {
            foreach ($request->file("images") as $i => $image) {
                $path = $image->storeAs($product->id, "image_$i.{$image->extension()}");

                // can't use storePubliclyAs because there are unknown issues
                Storage::setVisibility($path, 'public');

                ProductImage::query()->create([
                    "product_id" => $product->id,
                    "url" => Storage::url($path),
                    "is_thumbnail" => $i === 0,
                ]);
            }
        }

        if ($request->has("categories")) {
            foreach ($request->categories as $idOrName) {
                // if passed in an id
                if (ProductCategory::query()->find($idOrName)) {
                    $product->categories()->attach($idOrName);
                    continue;
                }

                // is a name instead
                $created = ProductCategory::query()->create([
                    "product_id" => $product->id,
                    "name" => $idOrName,
                ]);

                $product->categories()->attach($created->id);
            }
        }

        return response([
            "message" => "Success",
            "product" => $product->load(["images", "categories"]),
        ]);
    }

    public function show($id)
    {
        $product = Product::with(["images", "categories"])->find($id);

        if (!$product) {
            return response([
                "message" => "Could not find product with requested id"
            ], 400);
        }

        return response([
            "message" => "Success",
            "product" => $product,
        ]);
    }

    public function update(Request $request, $id)
    {
        $validation = Validator::make($request->all(), [
            "name" => "string",
            "description" => "string",
            "price" => "numeric",
            "quantity" => "numeric",
            "images.*" => "image|mimes:jpeg,png,jpg,gif,svg|max:2048",
            "categories" => "array",
            "categories.*" => "string|distinct",
        ]);

        if ($validation->fails()) {
            return response([
                "message" => "Validation failed",
                "errors" => $validation->errors(),
            ], 400);
        }

        $product = Product::query()->find($id);

        if (!$product) {
            return response([
                "message" => "Could not find product with requested id"
            ], 400);
        }

        $product->update($request->except(["categories", "images"]));

        if ($request->has("categories")) {
            $updatedCategories = array_map(function ($categoryIdOrName) use ($product) {
                // found as id
                if (ProductCategory::query()->find($categoryIdOrName)) {
                    return $categoryIdOrName;
                }

                $created = ProductCategory::query()->create([
                    "product_id" => $product->id,
                    "name" => $categoryIdOrName,
                ]);
                return $created->id;
            }, $request->categories);

            $product->categories()->sync($updatedCategories);
        }

        if ($request->hasFile("images")) {
            Storage::deleteDirectory($product->id);
            ProductImage::query()->where("product_id", $product->id)->delete();

            foreach ($request->file("images") as $i => $image) {
                $path = $image->storeAs($product->id, "image_$i.{$image->extension()}");

                // can't use storePubliclyAs because there are unknown issues
                Storage::setVisibility($path, 'public');

                ProductImage::query()->create([
                    "product_id" => $product->id,
                    "url" => Storage::url($path),
                    "is_thumbnail" => $i === 0,
                ]);
            }
        }

        return response([
            "message" => "Updated product id: $product->id",
            "product" => $product->load(["images", "categories"]),
        ]);
    }

    public function destroy($id)
    {
        Product::destroy($id);
        Storage::deleteDirectory($id);

        return response([
            "message" => "Product deleted",
        ]);
    }

    public function userProducts(Request $request, $id) {
        $products = Product::query()
            ->where("seller_id", $id)
            ->with(["images", "categories"])
            ->get();

        return response([
            "message" => "Returning " . $products->count() . " products",
            "products" => $products,
        ]);
    }

    public function search(
        Request $request,
        string $query,
        $includeProducts,
        $includeCategories,
        $limit = null,
    )
    {
        if (!$includeCategories && !$includeProducts) {
            return response([
                "message" => "Must include either categories or products",
            ], 400);
        }

        $body = [
            "message" => "Returning search results",
        ];

        if ($includeProducts) {
            $body["products"] = Product::query()
                ->where("name", "like", "%$query%")
                ->orWhere("description", "like", "%$query%")
                ->with(["images", "categories"])
                ->when(isset($limit), fn ($query, $limit) => $query->limit($limit))
                ->get();
        }

        if ($includeCategories) {
            $body["categories"] = ProductCategory::query()
                ->where("name", "like", "%$query%")
                ->when(isset($limit), fn ($query, $limit) => $query->limit($limit))
                ->get();
        }

        return response($body);
    }

    public function purchase(Request $request)
    {
        $ids = $request->ids;
        Product::query()->findMany($ids)->each(function ($product) {
            $product->quantity -= 1;
            $product->save();
        });

        return response([
            "message" => "Success",
        ]);
    }
}
