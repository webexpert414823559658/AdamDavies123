<?php

namespace Tests\Integration\Services;

use App\Events\LibraryChanged;
use App\Events\MediaSyncCompleted;
use App\Libraries\WatchRecord\InotifyWatchRecord;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use App\Services\FileSynchronizer;
use App\Services\MediaSyncService;
use getID3;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Mockery;
use Tests\Feature\TestCase;

class MediaSyncServiceTest extends TestCase
{
    use WithoutMiddleware;

    /** @var MediaSyncService */
    private $mediaService;

    public function setUp(): void
    {
        parent::setUp();

        $this->mediaService = app(MediaSyncService::class);
    }

    public function testSync(): void
    {
        $this->expectsEvents(LibraryChanged::class, MediaSyncCompleted::class);

        $this->mediaService->sync($this->mediaPath);

        // Standard mp3 files under root path should be recognized
        self::assertDatabaseHas(Song::class, [
            'path' => $this->mediaPath . '/full.mp3',
            // Track # should be recognized
            'track' => 5,
        ]);

        // Ogg files and audio files in subdirectories should be recognized
        self::assertDatabaseHas('songs', ['path' => $this->mediaPath . '/subdir/back-in-black.ogg']);

        // GitHub issue #380. folder.png should be copied and used as the cover for files
        // under subdir/
        $song = Song::wherePath($this->mediaPath . '/subdir/back-in-black.ogg')->first();
        self::assertNotNull($song->album->cover);

        // File search shouldn't be case-sensitive.
        self::assertDatabaseHas('songs', ['path' => $this->mediaPath . '/subdir/no-name.mp3']);

        // Non-audio files shouldn't be recognized
        self::assertDatabaseMissing('songs', ['path' => $this->mediaPath . '/rubbish.log']);

        // Broken/corrupted audio files shouldn't be recognized
        self::assertDatabaseMissing('songs', ['path' => $this->mediaPath . '/fake.mp3']);

        // Artists should be created
        self::assertDatabaseHas('artists', ['name' => 'Cuckoo']);
        self::assertDatabaseHas('artists', ['name' => 'Koel']);

        // Albums should be created
        self::assertDatabaseHas('albums', ['name' => 'Koel Testing Vol. 1']);

        // Albums and artists should be correctly linked
        $album = Album::whereName('Koel Testing Vol. 1')->first();
        self::assertEquals('Koel', $album->artist->name);

        // Compilation albums, artists and songs must be recognized
        $song = Song::whereTitle('This song belongs to a compilation')->first();
        self::assertNotNull($song->artist_id);
        self::assertTrue($song->album->is_compilation);
        self::assertEquals(Artist::VARIOUS_ID, $song->album->artist_id);

        $currentCover = $album->cover;

        $song = Song::orderBy('id', 'desc')->first();

        // Modified file should be recognized
        touch($song->path, $time = time());
        $this->mediaService->sync($this->mediaPath);
        $song = Song::find($song->id);
        self::assertEquals($time, $song->mtime);

        // Albums with a non-default cover should have their covers overwritten
        self::assertEquals($currentCover, Album::find($album->id)->cover);
    }

    public function testForceSync(): void
    {
        $this->expectsEvents(LibraryChanged::class, MediaSyncCompleted::class);

        $this->mediaService->sync($this->mediaPath);

        // Make some modification to the records
        /** @var Song $song */
        $song = Song::orderBy('id', 'desc')->first();
        $originalTitle = $song->title;
        $originalLyrics = $song->lyrics;

        $song->update([
            'title' => "It's John Cena!",
            'lyrics' => 'Booom Wroooom',
        ]);

        // Resync without forcing
        $this->mediaService->sync($this->mediaPath);

        // Validate that the changes are not lost
        /** @var Song $song */
        $song = Song::orderBy('id', 'desc')->first();
        self::assertEquals("It's John Cena!", $song->title);
        self::assertEquals('Booom Wroooom', $song->lyrics);

        // Resync with force
        $this->mediaService->sync($this->mediaPath, [], true);

        // All is lost.
        /** @var Song $song */
        $song = Song::orderBy('id', 'desc')->first();
        self::assertEquals($originalTitle, $song->title);
        self::assertEquals($originalLyrics, $song->lyrics);
    }

    public function testSelectiveSync(): void
    {
        $this->expectsEvents(LibraryChanged::class, MediaSyncCompleted::class);

        $this->mediaService->sync($this->mediaPath);

        // Make some modification to the records
        /** @var Song $song */
        $song = Song::orderBy('id', 'desc')->first();
        $originalTitle = $song->title;

        $song->update([
            'title' => "It's John Cena!",
            'lyrics' => 'Booom Wroooom',
        ]);

        // Sync only the selective tags
        $this->mediaService->sync($this->mediaPath, ['title'], true);

        // Validate that the specified tags are changed, other remains the same
        $song = Song::orderBy('id', 'desc')->first();
        self::assertEquals($originalTitle, $song->title);
        self::assertEquals('Booom Wroooom', $song->lyrics);
    }

    public function testSyncAllTagsForNewFiles(): void
    {
        // First we sync the test directory to get the data
        $this->mediaService->sync($this->mediaPath);

        // Now delete the first song.
        $song = Song::orderBy('id')->first();
        $song->delete();

        // Selectively sync only one tag
        $this->mediaService->sync($this->mediaPath, ['track'], true);

        // but we still expect the whole song to be added back with all info
        $addedSong = Song::findOrFail($song->id)->toArray();
        $song = $song->toArray();
        array_forget($addedSong, 'created_at');
        array_forget($song, 'created_at');
        self::assertEquals($song, $addedSong);
    }

    public function testSyncAddedSongViaWatch(): void
    {
        $this->expectsEvents(LibraryChanged::class);

        $path = $this->mediaPath . '/blank.mp3';
        $this->mediaService->syncByWatchRecord(new InotifyWatchRecord("CLOSE_WRITE,CLOSE $path"));

        self::assertDatabaseHas('songs', ['path' => $path]);
    }

    public function testSyncDeletedSongViaWatch(): void
    {
        $this->expectsEvents(LibraryChanged::class);

        static::createSampleMediaSet();
        $song = Song::orderBy('id', 'desc')->first();

        $this->mediaService->syncByWatchRecord(new InotifyWatchRecord("DELETE {$song->path}"));

        self::assertDatabaseMissing('songs', ['id' => $song->id]);
    }

    public function testSyncDeletedDirectoryViaWatch(): void
    {
        $this->expectsEvents(LibraryChanged::class, MediaSyncCompleted::class);

        $this->mediaService->sync($this->mediaPath);

        $this->mediaService->syncByWatchRecord(new InotifyWatchRecord("MOVED_FROM,ISDIR $this->mediaPath/subdir"));

        self::assertDatabaseMissing('songs', ['path' => $this->mediaPath . '/subdir/sic.mp3']);
        self::assertDatabaseMissing('songs', ['path' => $this->mediaPath . '/subdir/no-name.mp3']);
        self::assertDatabaseMissing('songs', ['path' => $this->mediaPath . '/subdir/back-in-black.mp3']);
    }

    public function testHtmlEntities(): void
    {
        $this->swap(
            getID3::class,
            Mockery::mock(getID3::class, [
                'analyze' => [
                    'tags' => [
                        'id3v2' => [
                            'title' => ['&#27700;&#35895;&#24195;&#23455;'],
                            'album' => ['&#23567;&#23721;&#20117;&#12371; Random'],
                            'artist' => ['&#20304;&#20489;&#32190;&#38899; Unknown'],
                        ],
                    ],
                    'encoding' => 'UTF-8',
                    'playtime_seconds' => 100,
                ],
            ])
        );

        /** @var FileSynchronizer $fileSynchronizer */
        $fileSynchronizer = app(FileSynchronizer::class);
        $info = $fileSynchronizer->setFile(__DIR__ . '/songs/blank.mp3')->getFileInfo();

        self::assertEquals('佐倉綾音 Unknown', $info['artist']);
        self::assertEquals('小岩井こ Random', $info['album']);
        self::assertEquals('水谷広実', $info['title']);
    }

    public function testOptionallyIgnoreHiddenFiles(): void
    {
        config(['koel.ignore_dot_files' => false]);
        $this->mediaService->sync($this->mediaPath);
        self::assertDatabaseHas('albums', ['name' => 'Hidden Album']);

        config(['koel.ignore_dot_files' => true]);
        $this->mediaService->sync($this->mediaPath);
        self::assertDatabaseMissing('albums', ['name' => 'Hidden Album']);
    }
}
