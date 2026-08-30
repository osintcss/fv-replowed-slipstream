<x-app-layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div style="margin-bottom: 1.5rem;">
                        <h3 style="font-size: 1.125rem; font-weight: 600; margin-bottom: 0.75rem;">Before You Play</h3>
                        <p style="margin-bottom: 1rem; line-height: 1.6;">This is an old Flash-based game. To play it, you need to install both of the following on your PC. We only support <strong>Windows 10</strong>.</p>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.75rem;">
                            <a href="https://downloads.los-network.com/files/flashplayer32_0r0_371_win.exe"
                                style="background: linear-gradient(135deg, #2d5016, #4a7c23); color: #fff; padding: 0.75rem 1.25rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: opacity 0.15s; box-shadow: 0 2px 8px rgba(0,0,0,0.15);"
                                onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                                <span style="font-size: 1.1rem;">&#x1F4E5;</span> 1. Download Flash Player (Windows)
                            </a>
                            <a href="https://downloads.los-network.com/files/palemoon-33.9.1.win64.installer.exe"
                                style="background: linear-gradient(135deg, #2d5016, #4a7c23); color: #fff; padding: 0.75rem 1.25rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: opacity 0.15s; box-shadow: 0 2px 8px rgba(0,0,0,0.15);"
                                onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                                <span style="font-size: 1.1rem;">&#x1F310;</span> 2. Download Pale Moon Browser (Win64)
                            </a>
                        </div>
                        <p style="font-size: 0.8rem; color: #9ca3af; margin-top: 0.5rem;">Install both, then open this site in Pale Moon to play.</p>
                    </div>

                    <hr class="border-gray-200 dark:border-gray-700 mb-6">

                    @if (is_dir(public_path('farmville/assets/hashed/assets')))
                        <h2>Assets exist. Go to the "Play" tab and enjoy!</h2>
                    @else
                        <h2>Assets don't exist.</h2>
                        <p>We need to do a routine that downloads all necessary files for the game to work.</p>
                        <p>It's up to you to decide when, but you need to do it.</p>
                        <p>Click the button below to start the process, and do not close or interrupt the server while it's doing its thing.</p>
                        <br>
                        <p>What will be done:</p>
                        <ul>
                            <li>The game's files will be downloaded from the Internet Archive.</li>
                            <li>The files will be extracted into the server's asset directory.</li>
                        </ul>
                        <br>
                        <button id="download-btn" class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:bg-gray-700 dark:focus:bg-white active:bg-gray-900 dark:active:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">{{ __('Download Assets') }}</button>
                        @if (is_dir(public_path('tmp')) && count(glob(public_path('tmp/') . "*")) == 4)
                        <button id="extract-btn" class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:bg-gray-700 dark:focus:bg-white active:bg-gray-900 dark:active:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">{{ __('Extract Assets') }}</button>
                        @endif
                        <div id="progress-container" style="display: none;">
                            
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function () {

            var downloadUrl = "https://archive.org/download/original-farmville/"
            var files = [
                "urls-bluepload.unstable.life-farmvilleassets.txt-shallow-20201225-045045-5762m-00000.warc.gz",
                "urls-bluepload.unstable.life-farmvilleassets.txt-shallow-20201225-045045-5762m-00001.warc.gz",
                "urls-bluepload.unstable.life-farmvilleassets.txt-shallow-20201225-045045-5762m-00002.warc.gz",
                "urls-bluepload.unstable.life-farmvilleassets.txt-shallow-20201225-045045-5762m-00003.warc.gz",
            ]

            const chunkSize = 10485760; 
            let downloadedData = [];
            let totalSize = [];
            let currentOffset = [];
            let currentFile = 1;
            let currentExtractOffset = 0;

            $('#download-btn').click(async function () {
                $('#progress-container').show();
                
                
                $('#progress').text(0);
                
                files.forEach( async (file, idx) => {
                    $('#progress-container').append(`<p><span id="progress-${idx}">File ${idx+1}/${files.length} Downloaded: 0</span>%</p>`);
                    await getFileSize(downloadUrl, file, idx)
                    
                })
                
            });

            $('#extract-btn').click(function(){
                console.log("Extract clicked")
                $('#progress-container').show();
                $('#progress-container').append(`<p>Extracted <span id="progress">0</span> files</p>`);
                $('#progress').text(0);
                extractAssets();
            })

            function extractAssets(){
                $.ajax({
                    url: "{{ route('extract.file') }}",
                    type: "POST",
                    headers: { "Content-Type": "application/json" },
                    data: JSON.stringify({
                        batchSize: 100,
                        offset: currentExtractOffset,
                        _token: "{{ csrf_token() }}" 
                    }),
                    success: function (response) {
                        console.log(response.files)
                        if (!response.done) {
                            currentExtractOffset = response.progress + 1
                            console.log(`Processed up to offset ${response.progress}, requesting next batch...`);
                            $(`#progress`).text(response.progress);
                            extractAssets(); 
                        } else {
                            console.log('Extraction complete.');
                        }
                    }
                });
            }

            async function getFileSize(downloadUrl, file, index) {
                let response = await fetch("{{ route('download.file') }}", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        url: downloadUrl+file,
                        _token: "{{ csrf_token() }}"
                    })
                });

                let result = await response.json();
                totalSize[index] = result.totalSize;
                console.log(`File size: ${totalSize[index]} bytes, Whole array ${totalSize}`);
                downloadChunk(downloadUrl, file, index);
            }

            async function downloadChunk(downloadUrl, file, index) {
                if(typeof currentOffset[index] === 'undefined') {
                    currentOffset[index] = 0;
                }
                let response = await fetch("{{ route('download.file') }}", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        url: downloadUrl+file,
                        start: currentOffset[index],
                        end: Math.min(currentOffset[index] + chunkSize - 1, totalSize[index] - 1),
                        totalSize: totalSize[index],
                        _token: "{{ csrf_token() }}"
                    })
                });

                let result = await response.json();
                $(`#progress-${index}`).text(`File: ${index+1}/${files.length} Downloaded: ${result.progress}`);
                if (result.progress < 100) {
                    currentOffset[index] += chunkSize;
                    downloadChunk(downloadUrl, file, index); 
                } else {
                    console.log("Download complete!");
                    currentFile++
                }
            }

        });
    </script>
</x-app-layout>
