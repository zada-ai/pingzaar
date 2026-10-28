import org.jetbrains.kotlin.gradle.tasks.KotlinCompile
import org.jetbrains.kotlin.konan.properties.Properties
import java.io.FileInputStream

plugins {
    alias(libs.plugins.android)
    alias(libs.plugins.kotlinAndroid)
    alias(libs.plugins.ksp)
}

val keystorePropertiesFile: File = rootProject.file("keystore.properties")
val keystoreProperties = Properties()
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
}

android {
    compileSdk = project.libs.versions.app.build.compileSDKVersion.get().toInt()

    defaultConfig {
        applicationId = libs.versions.app.version.appId.get()
        minSdk = project.libs.versions.app.build.minimumSDK.get().toInt()
        targetSdk = project.libs.versions.app.build.targetSDK.get().toInt()
        versionName = project.libs.versions.app.version.versionName.get()
        versionCode = project.libs.versions.app.version.versionCode.get().toInt()
        ksp {
            arg("room.schemaLocation", "$projectDir/schemas")
        }

        // Is this the neutral build that ships inside Zender?
        //
        // It changes two things, both following from "this app belongs to nobody
        // in particular":
        //
        //  - Pairing is scan-only. A credential form would have no server to post
        //    to until a QR supplies one. A build from source keeps the form,
        //    because whoever built it knows their own site.
        //  - The logo is hidden. The bundled drawable is a "BRAND" placeholder;
        //    showing it in a shipped app looks unfinished, and shipping real
        //    branding would be wrong for a white-labelled product. A self-build
        //    replaces drawable/logo.png with its own and gets it back.
        //
        // Off by default, so a clean checkout builds the customisable app. The
        // release turns it on:  ./gradlew assembleRelease -Pprebuilt=true
        val prebuilt = (project.findProperty("prebuilt") ?: "false").toString().toBoolean()
        buildConfigField("boolean", "PREBUILT", prebuilt.toString())

        // The Zender this build talks to.
        //
        // Required for a source build and meaningless for the prebuilt. Someone
        // building from source is building for their own install, so the address
        // belongs in configuration, not in a field their users retype on a phone
        // keyboard. The prebuilt has no address until a QR names one.
        val siteUrl = (project.findProperty("defaultSiteUrl") ?: "").toString().trim()
        if (!prebuilt && siteUrl.isEmpty()) {
            throw GradleException(
                "defaultSiteUrl is not set.\n\n" +
                "Set your Zender address in gradle.properties:\n" +
                "    defaultSiteUrl=https://sms.example.com/\n" +
                "or pass it for one build:\n" +
                "    ./gradlew assembleRelease -PdefaultSiteUrl=https://sms.example.com/\n\n" +
                "Only the prebuilt APK that ships with Zender omits it, because it " +
                "takes its address from the pairing QR (-Pprebuilt=true)."
            )
        }
        buildConfigField("String", "DEFAULT_SITE_URL", "\"" + siteUrl + "\"")
    }

    signingConfigs {
        create("release") {
            if (keystorePropertiesFile.exists()) {
                keyAlias = keystoreProperties["keyAlias"] as String
                keyPassword = keystoreProperties["keyPassword"] as String
                // rootProject.file, not file: the latter resolves against the app
                // module, so a bare "app.ks" became app/app.ks and the build failed
                // with "keystore file not found". keystore.properties sits in the
                // root, so paths in it should read as relative to the root too.
                storeFile = rootProject.file(keystoreProperties["storeFile"] as String)
                storePassword = keystoreProperties["storePassword"] as String
            }
        }
    }

    buildFeatures {
        viewBinding = true
        buildConfig = true
    }

    bundle {
        abi {
            enableSplit = true
        }
        density {
            enableSplit = true
        }
        language {
            enableSplit = false  // Disabled to support runtime locale changes
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            isZipAlignEnabled = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )

            // Sign only when the credentials are actually present. The block above
            // creates the config unconditionally, but leaving it unassigned meant
            // assembleRelease produced app-release-unsigned.apk every time, key or
            // no key. Assigning it conditionally keeps both cases working: we get a
            // signed APK, and a customer building from source without our keystore
            // still gets a build rather than a failure.
            if (keystorePropertiesFile.exists()) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
        debug {
            isMinifyEnabled = false
            isShrinkResources = false
            proguardFiles(
                getDefaultProguardFile("proguard-android.txt"),
                "proguard-rules.pro"
            )
        }
    }

    sourceSets {
        getByName("main").java.srcDirs("src/main/kotlin")
    }

    compileOptions {
        val currentJavaVersionFromLibs = JavaVersion.valueOf(libs.versions.app.build.javaVersion.get())
        sourceCompatibility = currentJavaVersionFromLibs
        targetCompatibility = currentJavaVersionFromLibs
    }

    dependenciesInfo {
        includeInApk = false
    }

    tasks.withType<KotlinCompile> {
        kotlinOptions.jvmTarget = project.libs.versions.app.build.kotlinJVMTarget.get()
    }

    // Where R and BuildConfig are generated, and it must match the Kotlin package.
    //
    // Deliberately a literal rather than applicationId. They happen to be equal
    // today, but binding them would mean that rebranding the app -- changing
    // app-version-appId in libs.versions.toml, which is the documented one-line
    // customisation -- would silently move R and BuildConfig to the new package
    // and break every import in the source. Changing the installed identity must
    // not require touching a single Kotlin file.
    namespace = "dev.titansys.smsgateway"

    lint {
        checkReleaseBuilds = false
        abortOnError = true
        warningsAsErrors = true

        // Pre-existing issues, recorded so lint stays useful for NEW ones rather
        // than drowning in 180 known ones. Regenerate by deleting the file and
        // running lintRelease; it is package-qualified, so a rebrand needs that.
        baseline = file("lint-baseline.xml")
    }
}

dependencies {
    implementation(libs.androidx.constraintlayout)
    implementation(libs.androidx.core.ktx)
    implementation(libs.eventbus)

    // Room
    implementation(libs.bundles.room)
    ksp(libs.androidx.room.compiler)

    // WorkManager for background processing
    implementation("androidx.work:work-runtime-ktx:2.9.0")


    // Material Components (optimized version)
    implementation("com.google.android.material:material:1.11.0")
    
    // Firebase - messaging only
    implementation("com.google.firebase:firebase-messaging-ktx:23.4.1")
    
    // Retrofit
    implementation("com.squareup.retrofit2:retrofit:2.11.0")
    implementation("com.squareup.retrofit2:converter-gson:2.11.0")
    implementation("com.squareup.okhttp3:logging-interceptor:4.12.0")

    // Lightweight QR scanning with CameraX + ZXing core
    implementation("androidx.camera:camera-camera2:1.3.1")
    implementation("androidx.camera:camera-lifecycle:1.3.1")
    implementation("androidx.camera:camera-view:1.3.1")
    implementation("com.google.zxing:core:3.5.2")

    // Core Splash Screen
    implementation(libs.androidx.core.splashscreen)
    
    // Testing dependencies
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.robolectric:robolectric:4.11.1")
    testImplementation("io.mockk:mockk:1.13.8")
    testImplementation("org.jetbrains.kotlinx:kotlinx-coroutines-test:1.7.3")
    testImplementation("androidx.test.ext:junit:1.1.5")
    testImplementation("androidx.test:core:1.5.0")
    
    // Android instrumentation testing
    androidTestImplementation("androidx.test.ext:junit:1.1.5")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.5.1")
    androidTestImplementation("io.mockk:mockk-android:1.13.8")
    androidTestImplementation("androidx.test:runner:1.5.2")
    androidTestImplementation("androidx.test:rules:1.5.0")
}
