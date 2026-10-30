import java.net.URI

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

fun prop(name: String): String =
    (project.findProperty(name) as String?)?.trim()?.takeIf { it.isNotEmpty() }
        ?: throw GradleException("Missing Gradle property '$name' (see android/gradle.properties)")

val baseUrl = prop("flatmates.baseUrl")
val baseUri = URI(baseUrl)
if (baseUri.scheme != "https" || baseUri.host.isNullOrBlank() || !baseUrl.endsWith("/")) {
    throw GradleException("flatmates.baseUrl must be an https:// URL ending with '/', got: $baseUrl")
}
if (baseUri.host.contains("YOUR-DOMAIN", ignoreCase = true) || baseUri.host.endsWith(".example")) {
    throw GradleException("flatmates.baseUrl still contains a placeholder domain. Set your real production domain.")
}
val appId = prop("flatmates.applicationId")
if (!Regex("^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)+$").matches(appId)) {
    throw GradleException("flatmates.applicationId must look like in.example.app, got: $appId")
}

// Release signing comes ONLY from environment variables set by CI from GitHub secrets.
val keystorePath: String? = System.getenv("ANDROID_KEYSTORE_PATH")?.takeIf { it.isNotBlank() }

android {
    namespace = "app.flatmates.android"
    compileSdk = 35

    defaultConfig {
        applicationId = appId
        minSdk = 24
        targetSdk = 35
        versionCode = prop("flatmates.versionCode").toInt()
        versionName = prop("flatmates.versionName")
        buildConfigField("String", "BASE_URL", "\"$baseUrl\"")
        buildConfigField("String", "ALLOWED_HOST", "\"${baseUri.host}\"")
        manifestPlaceholders["appLinkHost"] = baseUri.host
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    signingConfigs {
        if (keystorePath != null) {
            create("release") {
                storeFile = file(keystorePath)
                storePassword = System.getenv("ANDROID_KEYSTORE_PASSWORD")
                keyAlias = System.getenv("ANDROID_KEY_ALIAS")
                keyPassword = System.getenv("ANDROID_KEY_PASSWORD")
                enableV1Signing = false
                enableV2Signing = true
                enableV3Signing = true
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            signingConfig = signingConfigs.findByName("release")
        }
        debug {
            applicationIdSuffix = ".debug"
            versionNameSuffix = "-debug"
        }
    }

    buildFeatures { buildConfig = true }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }

    lint {
        abortOnError = true
        warningsAsErrors = false
        checkReleaseBuilds = false
        disable += setOf("GradleDependency", "OldTargetApi", "AndroidGradlePluginVersion", "NewerVersionAvailable")
        htmlReport = true
        xmlReport = true
    }

    packaging { resources { excludes += "/META-INF/{AL2.0,LGPL2.1}" } }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.activity:activity-ktx:1.9.3")
    implementation("androidx.core:core-splashscreen:1.0.1")
    implementation("androidx.webkit:webkit:1.12.1")
    testImplementation("junit:junit:4.13.2")
}
