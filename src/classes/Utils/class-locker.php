<?php

namespace Atte\Utils;
class Locker
{
    private $_filename;
    private $_fh = NULL;

    public function __construct( string $filename )
    {
        $this->_filename = realpath(dirname(__FILE__))."\\".$filename;
    }

    public function __destruct()
    {
        $this->unlock();
    }

    /**
     * Attempt to acquire an exclusive lock. Always check the return value!
     * @param bool $block If TRUE, we'll wait for existing lock release.
     * @return bool TRUE if we've acquired the lock, otherwise FALSE.
     */
    public function lock( bool $block = TRUE )
    {
        // Create the lockfile if it doesn't exist.
        if( ! is_file( $this->_filename ) ) {
            $created = @touch( $this->_filename );
            if( ! $created ) {
                return FALSE; // no file
            }
        }

        // Open a file handle if we don't have one.
        if( $this->_fh === NULL ) {
            $fh = @fopen( $this->_filename, 'r' );
            if( $fh !== FALSE ) {
                $this->_fh = $fh;
            } else {
                return FALSE; // no handle
            }
        }

        // Try to acquire the lock (blocking or non-blocking).
        $lockOpts = ( $block ? LOCK_EX : ( LOCK_EX | LOCK_NB ) );
        return flock( $this->_fh, $lockOpts ); // lock
    }

    /**
     * Release the lock. Also happens automatically when the Locker
     * object is destroyed, such as when the script ends. Also note
     * that all locks are released if the PHP process is force-killed.
     * NOTE: We DON'T delete the lockfile afterwards, to prevent
     * a race condition by guaranteeing that all PHP instances lock
     * on the exact same filesystem inode.
     */
    public function unlock()
    {
        if( $this->_fh !== NULL ) {
            flock( $this->_fh, LOCK_UN ); // unlock
            fclose( $this->_fh );
            $this->_fh = NULL;
        }
    }
}
