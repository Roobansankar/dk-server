<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderRequest;
use App\Http\Requests\Admin\StoreVideoRequest;
use App\Http\Requests\Admin\UpdateVideoRequest;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Support\VideoUploader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VideoController extends Controller
{
    public function index(Request $request)
    {
        $videos = Video::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->ordered()
            ->paginate($request->integer('per_page', 30));

        return VideoResource::collection($videos);
    }

    public function store(StoreVideoRequest $request)
    {
        $data = $request->safe()->except(['video']);
        $stored = VideoUploader::store($request->file('video'), 'videos');
        $data['video_path'] = $stored['video_path'];
        $data['thumbnail_path'] = $stored['thumbnail_path'];

        $video = Video::create($data);

        return (new VideoResource($video))->response()->setStatusCode(201);
    }

    public function show(Video $video)
    {
        return new VideoResource($video);
    }

    public function update(UpdateVideoRequest $request, Video $video)
    {
        $data = $request->safe()->except(['video']);

        if ($request->hasFile('video')) {
            $stored = VideoUploader::store($request->file('video'), 'videos');
            VideoUploader::delete($video->video_path);
            VideoUploader::delete($video->thumbnail_path);
            $data['video_path'] = $stored['video_path'];
            $data['thumbnail_path'] = $stored['thumbnail_path'];
        }

        $video->update($data);

        return new VideoResource($video);
    }

    public function destroy(Video $video)
    {
        VideoUploader::delete($video->video_path);
        VideoUploader::delete($video->thumbnail_path);
        $video->forceDelete();

        return response()->noContent();
    }

    public function reorder(ReorderRequest $request)
    {
        DB::transaction(function () use ($request) {
            foreach ($request->array('ids') as $position => $id) {
                Video::whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return response()->json(['message' => 'Order updated.']);
    }
}
