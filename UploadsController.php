<?php

namespace App\Http\Controllers;

use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManagerStatic as Image;
use \Sightengine\SightengineClient;

class UploadsController extends Controller
{
    // update avatar
    public function updateAvatar(Request $request)
    {
        $s3         = Storage::disk('s3');
        $image      = $request->file('avatar_upload');
        $authUserid = Auth::user()->id;

        // check if file exists
        if (!$image || !$image->isValid()) {
            return response()->json([
                'success' => true,
                'message' => 'You cannot submit a blank file',
            ]);
        }
        // validation rules
        $this->validate($request, [
            "avatar_upload" => 'required|mimes:jpeg,jpg,png|max:3000',
        ]);

        //grab the file extension like PNG, JPG, GIF etc
        $fileExtension = $image->getClientOriginalExtension();

        // create new file name based on user Id, timestamp and extension
        $filename = 'my_avatar_pic.' . $fileExtension;

        // define the local storage path for the file.
        $localFilePath = storage_path('uploads/avatars/' . $filename);

        //save the image file locally in the path defined above.
        $image = Image::make($image->getRealPath())->resize(400, null, function ($constraint) {
            $constraint->aspectRatio();
        })->save($localFilePath);

        /**
         * Now we're ready to analyze the image using the SightEngine AI Engine
         * Intialize the sightEngine. Use the API information saved in your .env file
         */

        $SightEngine = new SightengineClient(env('SIGHTENGINEUSER'), env('SIGHTENGINEKEY'));

        //analyze the locally stored image for nudity
        $imageCheck = $SightEngine->check(['nudity'])->set_file($localFilePath);

        /**
         * determine an acceptability threshold.
         *For our example, we will reject any image with a score greater than 0.60
         */

        $rawNudityProbability = $imageCheck->nudity->raw;
        $acceptableThreshhold  = 0.60;

        if ($rawNudityProbability > $acceptableThreshhold) {
            // remove from our storage destination if we don't want to keep it.
            unlink($localFilePath);

            //take action on the user. Send a warning message, log a report, investigate, ban them, notify the admin, etc
            //send a response back to the user
            return response()->json([
                'success' => true,
                'message' => 'Problem with the image upload!',
            ]);
        }
        //end nudity check

        // s3 path to where the upload will go based on env url
        $destinationPath = env('S3_UPLOADS_DIR') . 'avatars/' . $filename;

        // upload to s3
        if ($s3->put($destinationPath, file_get_contents($localFilePath), 'public')) {
            // remove from our server if successful upload
            unlink($localFilePath);
            // save user avatar in users table, avatar field
            Auth::user()->update([
                'avatar' => env('S3_URL') . $destinationPath,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Your avatar has been updated!',
            ]);
        }
        return response()->json([
            'success' => false,
            'message' => 'There was a problem uploading the avatar!',
        ]);
    }

}
