<?php

namespace App\Http\Controllers;

use App\Jobs\FetchReviewsJob;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use Aws\S3\S3Client;
use Illuminate\Http\Request;
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

        $product->save();

        $product->images = array_map(function ($file) use ($request, $product) {
            $image_name = time()."_".$request->title.".".$file->getClientOriginalExtension();
            $url = $file->storeAs("images", $image_name, "s3");

            $image = ProductImage::query()->create([
                "product_id" => $product->id,
                "url" => $url,
                "is_thumbnail" => $request->is_thumbnail,
            ]);

            $image->save();

            return $image;
        }, $request->file("images"));

        $product->categories = array_map(function ($value) use ($product) {
            $category = ProductCategory::query()->create([
                "product_id" => $product->id,
                "name" => $value,
            ]);

            $category->save();
            return $category;
        }, $request->categories);

        return response([
            "message" => "Success",
            "product" => $product,
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
            "seller_id" => "string",
            "name" => "string",
            "description" => "string",
            "price" => "numeric",
            "quantity" => "numeric",
            "images.*" => "image|mimes:jpeg,png,jpg,gif,svg|max:2048",
            "categories" => "array",
            "categories.*" => "string|distinct",
        ]);

        $product = Product::find($id);

        if (!$product) {
            return response([
                "message" => "Could not find product with requested id"
            ], 400);
        }

        $product->update($request->all());

        return response([
            "message" => "Updated product id: " . $product->id,
            "product" => $product,
        ]);
    }

    public function destroy($id)
    {
        Product::destroy($id);

        return response([
            "message" => "Product deleted",
        ]);
    }

    public function sellerProducts(Request $request, $user_id) {
        $products = Product::query()
            ->where("seller_id", $user_id)
            ->with(["images", "categories"])
            ->get();

        $reviews = FetchReviewsJob::dispatchSync($products);

        echo $reviews;

        return response([
            "message" => "Returning " . $products->count() . " products",
            "products" => $products,
        ]);
    }

    public function search(Request $request, string $query)
    {
        $products = Product::query()
            ->where("name", "like", "%$query%")
            ->orWhere("description", "like", "%$query%")
            ->with(["images", "categories"])
            ->limit(10)
            ->get();

        $categories = ProductCategory::query()
            ->where("name", "like", "%$query%")
            ->limit(10)
            ->get();

        return response([
            "message" => "Returning " . $products->count() . " products",
            "products" => $products,
            "categories" => $categories,
        ]);
    }
}
