<?php if (!defined('PmWiki')) exit();
/*
 * WikiGallery - automatic easy to use gallery extension for PmWiki
 * (c) 2006,2007 Stefan Schimanski <sts@1stein.org>
 *     2007 Picture height patch by JamesM
 *
 * Ideas from SimpleGallery by Bram Brambring <http://www.brambring.nl>
 * Some code from Qdig by Hagan Fox <http://qdig.sourceforge.net/>
 * 
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */


$WikiGalleryThumbProviders = array();

$HandleActions["thumbnail"] = 'WikiGalleryThumbnail';
function WikiGalleryThumbnail( $pagename, $auth = "read" ) {
    global $WikiGallery_UseAuthorization, $WikiGalleryThumbProviders, $WikiGallery_DefaultGroup;

    // get filename
    if( !isset( $_GET["image"] ) ) Abort('no image given');
    if( !isset( $_GET["group"] ) ) $group = $WikiGallery_DefaultGroup; else $group = $_GET["group"];
    $path = WikiGallerySecurePath( urldecode($_GET["image"]) );

    // group exists?
    if( !isset( $WikiGalleryThumbProviders[$group] ) ) Abort("Invalid gallery group \"$group\" given");
    $provider = $WikiGalleryThumbProviders[$group];

    // get size
    $width = intval(@$_GET["width"]);
    $height = intval(@$_GET["height"]);
    if( $width<0 || $width>1600 ) $width=0;
    if( $height<0 || $height>1200 ) $height=0;
    $resizeMode = urldecode(@$_GET["mode"]);

    // check authorization
    $pagename = fileNameToPageName( $path );
    if( $WikiGallery_UseAuthorization ) {
        $page = RetrieveAuthPage($pagename, $auth, true, READPAGE_CURRENT);
        if (!$page) Abort('?cannot read $pagename');
        PCache($pagename,$page);
    }

    // get image
    $provider->thumb( $path, $width, $height, $resizeMode );
    exit;
}

########################################################################

class ThumbProvider {
    var $group;

    function ThumbProvider( $group ) {
        global $WikiGalleryThumbProviders;
        $this->group = $group;
        $WikiGalleryThumbProviders[$group] = $this;
    }

    function getGroup() {
        return $this->group;
    }

    function thumbUrl( $path, $width, $height, $resizeMode="" ) {
        Abort( "Gallery ". $this->group . " does not implement thumbnails" );
    }

    function thumb( $path, $width, $height, $resizeMode="" ) {
        Abort( "Gallery ". $this->group . " does not implement thumby of thumbnail images" );
    }
}

########################################################################

class PhpThumbProvider extends ThumbProvider {
    var $phpThumbUrl;
    function PhpThumbProvider( $group, $phpThumbUrl ) {
        $this->ThumbProvider( $group );
        $this->phpThumbUrl = $phpThumbUrl;
    }

    function thumbUrl( $path, $width, $height, $resizeMode="" ) {
    	$url=$this->phpThumbUrl.'?';
    	if ($width!=0) {
    		$url .= 'w=' . urlencode($width) . '&';
    	}
    	if ($height!=0) {
    		$url .= 'h=' . urlencode($height). '&';
    	}
        if( $width!=0 && $height!=0 && strchr($resizeMode,"!") ) {
            $url .= 'iar=1&';
        }
        return $url . 'src=' . urlencode($path) . ' ';
    }
}

########################################################################

class InternalThumbProvider extends ThumbProvider {
    var $cleanupTimstamp;
    var $cacheBasePath;
    var $cacheWebPath;
    var $picturesBasePath;
    var $picturesWebPath;
    var $scaleMethod;

    function InternalThumbProvider( $group, $cacheBasePath, $cacheWebPath, 
                                    $picturesBasePath, $picturesWebPath, $scaleMethod="auto" ) {
        $this->ThumbProvider( $group );

        $this->cacheBasePath = $cacheBasePath;
        $this->cacheWebPath = $cacheWebPath;
        $this->picturesBasePath = $picturesBasePath;
        $this->picturesWebPath = $picturesWebPath;
        $this->scaleMethod = $scaleMethod;

        // cleanup of thumbnails
        global $WikiGallery_CleanupInterval;
        $this->cleanupTimestamp = $this->cacheBasePath . "/.cleanup-timestamp";
        if( !file_exists( $this->cleanupTimestamp ) ) {
            touch( $this->cleanupTimestamp );
        } else {
            // clean up, but not too often
            if( time()-filemtime($this->cleanupTimestamp )>$WikiGallery_CleanupInterval ) {
                //    WikiGalleryCleanupCache();
                register_shutdown_function( 'WikiGalleryCleanupCache', $this, getcwd() );
            }
        }
    }

    function thumbUrl( $path, $width, $height, $resizeMode="" ) {
        global $WikiGallery_UseAuthorization, $pagename;
        // we can use a direct url to the file if authorization is not needed
        if( !$WikiGallery_UseAuthorization ) {
            if( $width==0 && $height=0 )
                // give direct url to the original file
                return 'http://'.$_SERVER['HTTP_HOST']."/".preg_replace("/ /","%20", $this->picturesWebPath . $path);
            else {
                // give direct url to the cache file
                $thumbnail = $this->cacheFileName( $path, $width, $height, $resizeMode );
                $thumbname = $this->cacheBasePath . "/" . $thumbnail;
                $originalname = $this->picturesBasePath . "/" . $path;
                if( WikiGalleryIsFileAndNonZero($thumbname) && filemtime($thumbname)>=filemtime($originalname) ) {
                    // touch it so that it is not purged during cleanup
                    touch( $this->cacheBasePath . "/" . $thumbnail );
	  
                    // ok, thumbnail exists, otherwise fall through
                    return 'http://'.$_SERVER['HTTP_HOST']."/".preg_replace("/ /","%20", $this->cacheWebPath . $thumbnail);
                }
            }
        }
    
        $picpage = $this->group . "." . fileNameToPageName($path);
        $url = MakeLink( $picpage, $picpage, NULL, NULL, "\$LinkUrl" );

    	$url.='?action=thumbnail&';
    	if( $width!=0 ) {
    		$url .= 'width=' . urlencode($width) . '&';
    	}
    	if( $height!=0 ) {
    		$url .= 'height=' . urlencode($height). '&';
    	}
    	if( $resizeMode ) {
    		$url .= 'mode=' . urlencode($resizeMode) . '&';
    	}
        return $url . 'group=' . urlencode($this->group) . '&image=' . urlencode($path) . ' ';
        
    }
  
    function cacheFileName( $path, $width=0, $height=0, $resizeMode="" ) {
        global $WikiGallery_UseFlatCache;

        if( $width!=0 || $height!=0 )
            $size=intval($width)."x".intval($height);
        else
            $size = "original";

        if( strchr($resizeMode, "!") ) $size=$size . "-forcesize";

        $ext = substr( strrchr( $path, "." ), 1 );
        $name = $path . "/" . $size . "." . $ext;
        if( $WikiGallery_UseFlatCache )
            return preg_replace( "/\\//", "-", $name );
        else
            return $name;
    }
  
    function scaleGD( $original, $thumb, $width, $height, $resizeMode ) {
        global $WikiGallery_HighQualityResize;
    
        // libgd2 installed?
        $info = @gd_info();
        if( !$info ) return;
        $version = preg_replace('/[[:alpha:][:space:]()]+/', '', $info['GD Version']);
        if( !$version>=2 ) return;
    
        // get file format
        $format=WikiGalleryMimeType( $original );
        if( !$format ) return;
    
        // get current size
        list($origWidth, $origHeight) = getimagesize($original);
    
        // compute new height
        if( $width==0 && $height==0 ) { $width = $origWidth; $height = $origHeight; }
        else if( $width==0 ) $width = $origWidth*$height/$origHeight;
        else if( $height==0 ) $height = $origHeight*$width/$origWidth;
        else {
            // are width:height maximal values?
            if( !strchr($resizeMode,"!") ) {
                $newwidth = $origWidth*$height/$origHeight;
                $newheight = $origHeight*$width/$origWidth;
                if( $newwidth<$width ) $width=$newwidth;
                else if( $newheight<$height ) $height=$newheight;
            }
        }

        // load image
        switch( $format ) {
        case 'image/jpeg':
            $source = imagecreatefromjpeg($original);
            break;
        case 'image/gif':
            $source = imagecreatefromgif($original);
            break;
        case 'image/png':
            $source = imagecreatefrompng($original);
            break;
        }
    
        if( $source ) {
            // scale
            $dest = imagecreatetruecolor( $width,$height );
            imagealphablending( $dest, false );
            if( $WikiGallery_HighQualityResize )
                imagecopyresampled($dest, $source, 0, 0, 0, 0, $width, $height, $origWidth, $origHeight);
            else
                imagecopyresized($dest, $source, 0, 0, 0, 0, $width, $height, $origWidth, $origHeight);
      
            // store image
            @imagejpeg($dest, $thumb);
        }
    }
  
    function scaleIM( $original, $thumb, $width, $height, $resizeMode ) {
        global $WikiGallery_ImageMagickPath, $WikiGallery_HighQualityResize;
    
        // get size
        if( $width==0 ) $size="x$height";
        elseif( $height==0 ) $size=$width."x";
        else $size=$width."x".$height;
        if( strchr($resizeMode,"!") ) $size=$size."!";
        
        // call imagemagick's convert to scale
        if( $WikiGallery_HighQualityResize )
            $command = $WikiGallery_ImageMagickPath . "convert -size $size -resize $size +profile \"*\" " . 
                escapeshellarg($original) . " " . escapeshellarg($thumb);
        else
            $command = $WikiGallery_ImageMagickPath . "convert -scale $size " . 
                escapeshellarg($original) . " " . escapeshellarg($thumb);
        @system( $command );
    }
  
    function scale( $original, $thumb, $width, $height, $resizeMode ) {
        if( !WikiGalleryIsFileAndNonZero( $thumb ) && is_file( $original ) ) {
            // which method to use?
            $im = false;
            $gd = false;
            if( $this->scaleMethod=="auto" ) { $im = true; $gd = true; }
            else if( $this->scaleMethod=="imagemagick" ) { $im = true; }
            else if( $this->scaleMethod=="libgd2" ) { $gd = true; }
      
            // try libgd2
            if( $im && !WikiGalleryIsFileAndNonZero( $thumb ) ) 
                $this->scaleIM( $original, $thumb, $width, $height, $resizeMode );
            if( $gd && !WikiGalleryIsFileAndNonZero( $thumb ) ) 
                $this->scaleGD( $original, $thumb, $width, $height, $resizeMode );
      
            // did one work?
            if( !WikiGalleryIsFileAndNonZero( $thumb ) ) {
                Abort("Unable to generate thumbnail, check whether imagemagick is installed or your PHP support libgd2.<br>");
            }
        }
    }  

    function thumb( $path, $width, $height, $resizeMode="" ) {
        // exists?
        $pagename = fileNameToPageName( $path );
        $original = $this->picturesBasePath . "/" . $path;
        if( !is_file( $original ) ) Abort('image doesn\'t exist');
    
        // resize?
        if( $width==0 && $height==0 ) {
            $filename = $original;
        } else {
            // resize
            $filename = $this->cacheBasePath . "/" . $this->cacheFileName( $path, $width, $height, $resizeMode );
            $exists = WikiGalleryIsFileAndNonZero($filename);
            if( !$exists || (filemtime($filename)<filemtime($original)) ) {
                if( is_file($filename) ) 
                    // if it already there, it must be updated. So remove it to avoid trouble overwriting it
                    unlink($filename);
                else {
                    // make directory
                    $dir = dirname($filename); 
                    mkdirp($dir);
                }
          
                // call ImageMagick or GD to scale
                $this->scale( $original, $filename, $width, $height, $resizeMode );
            } else {
                // touch it so that it is not purged during cleanup
                touch( $filename );
            }
        }
    
        // Checking if the client is validating his cache and if it is current.
  $etag = md5( $original . filemtime($original) . filesize($original) );
  header('ETag: '.$etag);
  if( (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && 
      strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])==filemtime($original)) ||
      (isset($_SERVER['HTTP_IF_NONE_MATCH']) &&  $_SERVER['HTTP_IF_NONE_MATCH'] == $etag) ) {
    // Client's cache IS current, so we just respond '304 Not Modified'.
    header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($original)).' GMT', true, 304);
  } else {
    // Image not cached or cache outdated, we respond '200 OK' and output the image.
    header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($original)).' GMT', true, 200);
    header('Content-Length: '.filesize($filename));
    header("Content-type: " . WikiGalleryMimeType($original) );
    header("Pragma: ");
    header('Expires: '.gmdate('D, j M Y H:i:s T', time() + 600));
    header("Cache-Control: max-age=600, must-revalidate");
    print file_get_contents( $filename );
  }
    }
}


function WikiGalleryCleanupCache( $provider, $dir ) {
    global $WikiGallery_CleanupDelay,
        $WikiGallery_CleanupTimestamp, 
        $WikiGallery_FindPath;

    // mark that cleanup was run
    chdir( $dir ); // work around for php bug. In shutdown functions the path is corrupted
    touch( $provider->cleanupTimestamp );

    // delete old files
    $command = $WikiGallery_FindPath . "find " . escapeshellarg($provider->cacheBasePath) . 
        " -type f -mtime +$WikiGallery_CleanupDelay -exec rm -f {} \\;";  
    if( @system( $command )<0 ) {
        Abort( "Error during cleanup of old thumbnails" );
    }
  
    // delete empty directories
    $command = $WikiGallery_FindPath . "find " . escapeshellarg($provider->cacheBasePath) .
        " -depth -type d -empty -exec rmdir {} \\;";
    if( @system( $command )<0 ) {
        Abort( "Error during cleanup of old thumbnails while deleting empty directories" );
    }
}
