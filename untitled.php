<?php

namespace ULC\Http\Controllers\Shared\Admin;

use Str;
use Thumbs;
use URL;
use File;
use Gate;
use View;
use Route;
use Config;
use Request;
use DateTime;
use Debugbar;
use Response;
use Exception;
use Throwable;
use Carbon\Carbon;
use ULC\Models\Image;
use ULC\Models\Scope;
use ULC\Models\Blog\Tag;
use ULC\Models\Blog\Post;
use ULC\Rules\RedirectType;
use ULC\Models\Blog\Category;
use ULC\Facades\HtmlToMarkdown;
use Yajra\Datatables\Datatables;
use ULC\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Database\Eloquent\Collection;
use ULC\Http\Requests\Admin\Blog\EditPostsRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ULC\Http\Requests\Admin\Blog\GetTaxomomyRequest;
use ULC\Http\Requests\Admin\Blog\PreviewPostRequest;
use ULC\Http\Requests\Admin\Blog\UploadPostImageRequest;
use ULC\Http\Requests\Admin\Blog\DisplayPostsAjaxRequest;
use ULC\Http\Controllers\Shared\BlogController as ClientBlogController;

class BlogController extends Controller
{
    protected $resource;

    protected $firstBreadCrumb;

    public function __construct()
    {
        $model = (new $this->model);

        $this->resource = Str::slug($model->getTable());

        View::share('resource', $this->resource);

        if (Request::route()) {
            $this->firstBreadCrumb = Str::title(explode('/', Request::route()->getPrefix())[1]);
        }
    }

    public function index()
    {
        $this->authorize('view_posts');

        return view('admin.blog.posts', [
            'crumbs' => [
                $this->firstBreadCrumb,
            ],
            'scopes' => Scope::all(),
        ]);
    }

    public function postsAjax(DisplayPostsAjaxRequest $request)
    {
        $posts = $this->model::select(collect($request->columns)
            ->pluck('name', 'name')
            ->except('action')
            ->add('allow_comments')
            ->toArray())
            ->with([
                'scope',
                'revisions'
            ])
            ->withCount('comments')
            ->where('status', '<>', 'revision');

        return DataTables::of($posts)
            // Action button column
            ->addColumn('action', function ($post) {

                if ($post->allow_comments) {
                    $commentingLink = "<a class='btn btn-sm btn-warning dataTable-action disable-comments' data-id='$post->id' href='" .
                        route("$this->resource.disable_comments", $post->id) . "'>Disable Commenting</a>";
                } else {
                    $commentingLink = "<a class='btn btn-sm btn-success dataTable-action enable-comments' data-id='$post->id' href='" .
                        route("$this->resource.enable_comments", $post->id) . "'>Enable Commenting</a>";
                }

                return "<a class='btn btn-sm btn-primary dataTable-action edit-record' href='" .
                    route("$this->resource.edit", $post->id) .
                    "'>Edit</a>" .
                    $commentingLink .
                    "<button class='btn btn-sm btn-danger dataTable-action delete-record' data-id='$post->id' data-toggle='modal' data-target='#deletionWarning'>Delete</button>";
            })
            // Scope (Website) column
            ->addColumn('scope', function ($post) {
                return $post->scope->name;
            })
            // Title column
            ->editColumn('title', function ($post) {
                $commentBubble = $post->comments_count ? 'fa-commenting' : 'fa-commenting-o';
                $commentsRoute = Str::singular($this->resource) . '-comments.index';
                $commentMessage = '<div class="col-sm-5">';
                $commentMessage .= $post->comments_count ? "<a href='" .
                    route($commentsRoute) .
                    "?search=" .
                    htmlentities($post->title) .
                    "'>" : '';
                $commentMessage .= "<i class='fa $commentBubble'></i> {$post->comments_count} Comments";
                $commentMessage .= $post->comments_count ? '</a>' : '';
                $commentMessage .= '</div>';

                $revisions = '<div class="col-sm-7">';
                if ($post->revisions->count()) {
                    $revisions .= sprintf('<a class="btn btn-sm btn-link" data-toggle="collapse" data-target="#revisions-%s">Revisions</a>',
                        $post->id);
                    $revisions .= sprintf('<div id="revisions-%s" class="panel panel-primary panel-collapse collapse">',
                        $post->id);
                    $revisions .= '<ul class="list-group">';
                    foreach ($post->revisions->sortby('updated_at', SORT_REGULAR, true) as $revision) {
                        $revisions .= sprintf('<li class ="list-group-item"><small>Updated: <a class="dropdown-item" href="%s">%s</a></small></li>',
                            route($revision->restoreRoute, $revision->id),
                            $revision->updated_at->format('M d, Y @ h:i'));
                    }
                    $revisions .= '</ul>';
                    $revisions .= '</div>';
                }
                $revisions .= '</div>';

                $retval = "<p>";
                $retval .= $post->status == 'publish' ? "<a href='" .
                    route($post->routeName, $post->slug, true, $post->scope) .
                    "'>$post->title</a>" : $post->title;
                $retval .= "</p><div class='row'>$commentMessage$revisions</div>";

                return $retval;
            })
            // Status column
            ->editColumn('status', function ($post) {
                switch ($post->status) {
                    case 'publish':
                        return "<span class='text-success' data-control-id='$post->id'>Published</span>";
                    default:
                        return "<span class='text-muted' data-control-id='$post->id'>Draft</span>";
                }
            })
            ->setRowClass(function ($post) {
                if ($post->published_at->isFuture()) {
                    $class = 'highlight-muted';
                }

                return $class ?? '';
            })
            ->rawColumns([
                'status',
                'title',
                'action',
            ])
            ->make(true);
    }

    /**
     * @param EditPostsRequest $request
     * @param Model            $post
     * @return RedirectResponse
     * @throws Exception
     */
    public function update(EditPostsRequest $request, Model $post)
    {
        $post->title = $request->title;
        $post->scope_id = $request->scope;
        $post->content_raw = $request->contentHtml;
        $post->meta_description = $request->metaDescription;
        $post->excerpt = $request->excerpt ?: null;
        $post->focus_keyword = $request->keyword ?: null;

        if (Gate::allows('modify_post_status')) {
            $post->status = $request->status;
        } else {
            $post->status = $post->status ?: 'pending';
        }

        $post->published_at = Carbon::createFromFormat('Y-m-d H:i',
            "{$request->publishedAt} {$request->publishedAtTime}");

        $scope = Scope::find($request->scope);
        $originalScope = Scope::find($request->originalScope);

        $scopeFolder = parse_tld($scope->name, true);
        $image_base_dir = "assets/$scopeFolder/blog/";

        $fullyMarkdowned = HtmlToMarkdown::setDefaultAltTag($post->title)
            ->setImagePath("/{$image_base_dir}scaled")
            ->convert($request->contentHtml);

        $imageIds = [];
        if (!empty($request->images)) {
            foreach ($request->images as $image_key => $image_data) {
                $image = Image::where('image_src', $image_data['original'])
                    ->where(function ($q) use ($post) {
                        $q->orWhereHas('posts', function ($q) use ($post) {
                            return $q->where('scope_id', $post->scope_id);
                        })
                            ->orWhereHas('sermons', function ($q) use ($post) {
                                return $q->where('scope_id', $post->scope_id);
                            });
                    })
                    ->first();

                if (!$image) {
                    $image = new Image();
                }

                $image_location_dir = 'assets/' .
                    parse_tld($originalScope ? $originalScope->name : $scope->name, true) . '/blog/';
                // Move existing image to new location/filename
                clearstatcache();

                $sourceFile = public_path('assets/shared/blog/upload/' . $image_data['original']);
                if (!file_exists($sourceFile)) {
                    $sourceFile = public_path($image_location_dir . $image_data['original']);
                }

                if (!file_exists($sourceFile)) {
                    return back()
                        ->with('error', 'Image not found to rename: ' . $image_data['original'])
                        ->withInput();
                }

                $revisedImage = false;

                if ($sourceFile != $destinationFile = public_path($image_base_dir . $image_data['filename'])) {
                    if (file_exists($destinationFile)) {
                        // Revisions move old images into a revision folder,
                        // to keep revisions working correctly we need to rename the old image
                        $oldImage = Image::where('image_src', $image_data['filename'])
                            ->whereHas($post->getTable(), function ($q) use ($post) {
                                return $q->where('id', $post->id)
                                    ->orWhereIn('id', $post->revisions()->get()->pluck('id')->toArray());
                            })->get()->filter(function ($image) {
                                return !Str::contains($image->scaled_location, ['revisions']);
                            })->first();

                        if ($image->is($oldImage)) {
                            $image = new Image();
                        }

                        // Rename image and scaled image by appending '-rev' + timestamp to prevent overwriting
                        $oldImageParts = explode('.', $oldImage->filename);
                        $newNameForOldImage = $oldImageParts[0] . '-rev'. Carbon::now()->timestamp . '.'. $oldImageParts[1];
                        File::move($destinationFile,  public_path($image_base_dir . $newNameForOldImage));

                        if (file_exists($oldScaledFileLocation = public_path($image_base_dir . 'scaled/' . $image_data['filename']))) {
                            File::move($oldScaledFileLocation, public_path($image_base_dir . 'scaled/' . $newNameForOldImage));
                        }

                        $oldImage->image_src = $newNameForOldImage;
                        $oldImage->save();

                        $revisedImage = true;
                    }

                    // Move the file to the correct directory
                    File::move($sourceFile, $destinationFile);
                    // Update references in the post content
                    $post->content_html = preg_replace(
                        "/upload\/{$image_data['filename']}/",
                        $image_data['filename'],
                        $fullyMarkdowned
                    );
                    // Update the filename
                    $image->image_src = $image_data['filename'];
                }

                $image->image_alt = $image_data['label'];

                // Save scaled dimensions
                $image->scaled_width = $image_data['scaledW'] ?: 0;
                $image->scaled_height = $image_data['scaledH'] ?: 0;

                if (!$revisedImage) {
                    // Deletes old scaled versions
                    File::delete(public_path($image_location_dir . 'scaled/' . $image_data['original']));
                    File::delete(public_path($image_location_dir . 'scaled/' . $image_data['filename']));
                    File::delete(public_path($image_base_dir . 'scaled/' . $image_data['original']));
                    File::delete(public_path($image_base_dir . 'scaled/' . $image_data['filename']));
                }

                if (($image_data['scaledW'] && $image_data['scaledH'])) {
                    $image->scaled_location = Thumbs::crop(
                        sprintf('assets/%s/blog/%s', $scopeFolder, $image->filename),
                        'scaled',
                        $image_data['scaledW'],
                        $image_data['scaledH'],
                    );
                } else {
                    copy(
                        public_path($image_base_dir . $image_data['filename']),
                        public_path($image_base_dir . 'scaled/' . $image_data['filename'])
                    );
                }

                $image->save();
                File::delete(public_path('/assets/shared/blog/upload/uploadThumbs/' . $image_data['original']));

                $imageIds[$image->id] = [
                    'is_default' => $request->default_image == $image->id ||
                        strpos($image_data['original'], $request->default_image) !== false ||
                    $image_key == $request['default_image']
                ];

                // Replace references to images in post
                if (isset($image_data['original']) && $image_data['original'] != $image_data['filename']) {
                    $regex = preg_replace(
                        '/\s/',
                        '(\s|%20)',
                        preg_quote($image_data['original'], '/')
                    );
                    $fullyMarkdowned = preg_replace(
                        "/{$regex}/",
                        $image_data['filename'],
                        $fullyMarkdowned
                    );
                }
                // Some <alt> tags imported from WordPress do not match the <alt> tags in the post itself
                $fullyMarkdowned = preg_replace(
                    "/(!\[)[^\]]*?(]\([^\[\]]*?{$image_data['filename']}\))/",
                    '${1}' . $image_data['label'] . '${2}',
                    $fullyMarkdowned
                );
            }
        }

        $post->content_html = $fullyMarkdowned;

        $post->slug = (
        !Post::where('slug', $request->slug)->where('id', '<>', $post->id)->first() ?
            $request->slug :
            "$request->slug-" . Str::slug(Carbon::now()->toDateTimeString())
        );

        if ((($post->isDirty('slug') && $post->getRawOriginal('slug') !== null) ||
                ($post->isDirty('scope_id') && $post->getRawOriginal('scope_id') !== null)) &&
            $request->makeRedirect &&
            ($post->status == 'publish' || $post->getRawOriginal('status') == 'publish')) {
            $originalScope = Scope::find($post->getRawOriginal('scope_id'));

            $redirect = new \ULC\Models\Redirect();
            $redirect->scope_id = $post->getRawOriginal('scope_id');
            $redirect->pattern = addcslashes(route($post->routeName, $post->getRawOriginal('slug'), $originalScope, false),
                '/.?');
            $redirect->redirect = route($post->routeName, $post->slug, $scope);
            $redirect->save();
        }

        $post->save();

        // sync new/updated images
        $post->images()->sync($imageIds);

        //* categories
        $post->categories()->sync(collect($request->categories)->mapWithKeys(function ($category) {
            if (is_numeric($category)) {
                $id = $category;
            } else {
                $newCategory = new Category();
                $newCategory->name = $category;
                $newCategory->slug = Str::slug($category);
                $newCategory->scope_id = Request::input('scope');
                $newCategory->save();
                $id = $newCategory->id;
            }

            return [$id => ['scope_id' => Request::input('scope')]];
        })->toArray());

        //* tags
        $this->sync('tags', $post);

        return redirect(route("$this->resource.edit", $post->id))
            ->with('message', 'Successfully saved ' . Str::singular($post->routeName));
    }

    private function sync($syncItems, $post)
    {
        $$syncItems = [];
        if (Request::input($syncItems)) {
            foreach (Request::input($syncItems) as $syncId) {
                if (is_numeric($syncId)) {
                    ${$syncItems}[$syncId]['scope_id'] = Request::input('scope');
                } else {
                    /** @var Model $syncItem */
                    $syncItem = $post->$syncItems()->getRelated();
                    $syncItem->name = $syncId;
                    $syncItem->slug = Str::slug($syncId);
                    $syncItem->scope_id = Request::input('scope');
                    $syncItem->save();
                    ${$syncItems}[$syncItem->id]['scope_id'] = Request::input('scope');
                }
            }
        }
        $post->$syncItems()
            ->sync($$syncItems);
    }

    /**
     * Returns a preview from raw HTML
     * DO NOT store unfiltered data
     *
     * @param PreviewPostRequest $request
     * @return array
     * @throws Exception
     */
    public function preview(PreviewPostRequest $request)
    {
        $scope = Scope::find($request->scope);

        $model = new $this->model();
        $model->title = $request->title;
        $model->slug = Str::slug($request->title);
        $model->content_html = HtmlToMarkdown::setImagePath(sprintf("/assets/%s/blog/scaled", parse_tld($scope->name, true)))
            ->timestampImages()
            ->convert($request->post);
        $model->published_at = today();

        Config::set('scope.id', $scope->id);
        Config::set('scope.name', $scope->name);
        Config::set('scope.domain', $scope->domain);
        Config::set('scope.view', parse_tld($scope->domain, true));
        Route::group([
            'middleware' => 'web',
            'namespace'  => 'ULC\Http\Controllers',
        ], function () {
            require base_path('routes/web.php');
        });

        $routes = Route::getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        URL::setRoutes(Route::getRoutes());

        $blogController = new ClientBlogController();

        $sidebar = $blogController->getSidebar();

        try {
            $content = view('blog.page', [
                'post'              => $model,
                'sidebarCategories' => $sidebar['categories'],
                'featured'          => $sidebar['featured'],
                'isSubscribed'      => false,
            ])->render();
        } catch (Throwable $e) {
            report($e);
            $content = '<div style="display:flex; align-items:center; height: 100vh;"><h1 style="margin: auto;">Preview Not Available</h1></div>';
        }

        return compact('content');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function create()
    {
        return $this->edit(new $this->model());
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param EditPostsRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(EditPostsRequest $request)
    {
        return $this->update($request, (new $this->model()));
    }

    /**
     * @param Model $model
     * @return Factory|\Illuminate\View\View
     * @throws AuthorizationException
     */
    public function edit(Model $model)
    {
        $this->authorize('edit_posts');

        /** @var integer $scopeId */
        $scopeId = null;
        if ($model->id) {
            $scopeId = $model->scope->id;
        } elseif ($scopeId = old('scope')) {
        } elseif ($selectedCat = Category::find(old('categories')[0] ?? '')) {
            $scopeId = $selectedCat->scope_id;
        } elseif ($selectedTag = Tag::find(old('tags')[0] ?? '')) {
            $scopeId = $selectedTag->scope_id;
        }

        if (!$scopeId) {
            $categories = $tags = new Collection();
        } else {
            $categories = $model->categories()->getRelated()->withCount('posts')->scoped($scopeId)->get();
            $tags = $model->tags()->getRelated()->withCount('posts')->scoped($scopeId)->get();
        }

        $model->load([
            'scope',
            'revisions' => function (HasMany $q) {
                $q->orderBy('updated_at', 'desc');
            },
        ]);
        $model->load(['scope']);

        $scopes = Scope::all();

        $modelStatuses = [
            null      => 'Select ...',
            'pending' => 'Draft',
        ];

        if (Gate::allows('modify_post_status') || $model->status == 'publish') {
            $modelStatuses['publish'] = 'Published';
        }

        if ($model->status == 'revision') {
            $modelStatuses['revision'] = 'Revision';
        }

        if ($model->scope) {
            $sitename = $imageLoc = parse_tld($model->scope->name, true);
        } elseif (old('scope')) {
            $sitename = $imageLoc = parse_tld($scopes->where('id', old('scope'))->first()->name, true);
        } else {
            $sitename = config('scope.name');
            $imageLoc = 'shared';
        }

        return view('admin.blog.edit-post', [
            'post'         => $model,
            'postStatuses' => $modelStatuses,
            'categories'   => $categories,
            'postCount'    => $model->count(),
            'tags'         => $tags,
            'scopes'       => $scopes,
            'scopetag'     => $sitename,
            'imageLoc'     => $imageLoc,
            'crumbs'       => [
                $this->firstBreadCrumb,
                '<a href="' . route("$this->resource.index") . '">' . Str::title($this->resource) . '</a>',
            ],
        ]);
    }

    public function destroy(Model $model)
    {
        Request::validate([
            'redirect' => ['nullable', 'regex:/^\/?[\w\.\/\-]*$/', new RedirectType($model->scope_id)],
        ]);

        $this->authorize('delete_posts');

        if ($destination = Request::get('redirect')) {
            $redirect = new \ULC\Models\Redirect();
            $redirect->scope_id = $model->scope_id;
            $redirect->redirect = $destination;
            $redirect->pattern = route('blogPost', $model->slug, false, $model->scope);
            $redirect->save();
        }

        if (!$model) {
            return response()->json([
                'result' => false,
            ]);
        }

        $model->delete();

        return response()->json([
            'result' => [
                'id'   => $model->id,
                'name' => $model->title,
            ],
        ]);
    }

    public function restorePost(Post $post)
    {
        Gate::authorize('edit_posts');

        try {
            $post->restoreRevision();
        } catch (\ErrorException $e) {
            return back()->withErrors($e->getMessage());
        }

        return Redirect::route($post->editRoute, $post->id)
            ->with('message', 'Post Revision Restored');
    }

    public function getTaxonomy(GetTaxomomyRequest $request)
    {
        $model = new $this->model();
        $selectedTags = collect(json_decode($request->tagsSelected))->pluck('id')->toArray();
        $selectedCategories = collect(json_decode($request->categoriesSelected))->pluck('id')->toArray();

        $categories = $model->categories()->getRelated()->withCount('posts')->scoped($request->website)->get();
        $tags = $model->tags()->getRelated()->withCount('posts')->scoped($request->website)->get();

        return Response::json([
            'tags'       => View::make(
                'admin.partials.category-post',
                [
                    'items'         => $tags,
                    'selected'      => $selectedTags,
                    'taxonomyInput' => 'tags',
                    'type'          => 'tag',
                ]
            )->render(),
            'categories' => View::make(
                'admin.partials.category-post',
                [
                    'items'         => $categories,
                    'selected'      => $selectedCategories,
                    'taxonomyInput' => 'categories',
                    'type'          => 'category',
                ]
            )->render(),
        ]);
    }

    public function uploadImages(UploadPostImageRequest $request)
    {
        $post = new $this->model();
        // checking file is valid.
        if ($request->file->isValid()) {
            $file = $request->file;
            $destinationPath = 'assets/shared/blog/upload'; // upload path
            $extension = $file
                ->getClientOriginalExtension(); // getting image extension
            $size = $file
                ->getSize(); // getting image size
            list($width, $height) = getimagesize($file);
            $date = DateTime::createFromFormat('U.u', microtime(true));
            $ident = $date->format("Uu");
            $fileName = $file->getClientOriginalName() ?: $ident . '.' . $extension; // renaming image
            $file
                ->move(public_path($destinationPath), $fileName); // uploading file to given path

            $file = [
                'ident'      => $ident,
                'name'       => $fileName,
                'alt'        => $post->title . " ($fileName)",
                'size'       => $size,
                'url'        => "/$destinationPath/$fileName",
                'scaledW'    => $width,
                'scaledH'    => $height,
            ];

            // Delete the old thumb file before creating it anew.
            File::delete(public_path("$destinationPath/uploadThumbs/$fileName"));
            $file['thumbnailUrl'] = Thumbs::crop(sprintf('%s/%s', $destinationPath, $fileName), 'uploadThumbs', 190, 190);

            Debugbar::disable();

            return json_encode([
                'files'    => [$file],
                'location' => url("$destinationPath/$fileName"),
            ]);
        } else {
            return response('The uploaded image is invalid.', 500);
        }
    }

    public function allowCommenting(Model $model, $enable = true)
    {
        $this->authorize('edit_posts');

        $model->allow_comments = $enable;
        $saved = $model->save();

        if (\Request::expectsJson()) {
            return response()->json([
                'success' => $saved,
                'id'      => $model->id,
                'enabled' => $model->allow_comments,
            ]);
        } else {
            return back();
        }
    }

    public function disallowCommenting(Model $model)
    {
        return $this->allowCommenting($model, false);
    }
}
