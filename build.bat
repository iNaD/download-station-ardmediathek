:: Delete old data
del ardmediathek.host
:: create the .tar.gz
7z a -ttar -so ardmediathek INFO ardmediathek.php | 7z a -si -tgzip ardmediathek.host
