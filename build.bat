:: Delete old data
del ardmediathek.host
del provider.php

:: get recent version of the provider base class
copy /Y ..\provider-boilerplate\src\provider.php provider.php

:: create the .tar.gz
7z a -ttar -so ardmediathek INFO ardmediathek.php provider.php | 7z a -si -tgzip ardmediathek.host
