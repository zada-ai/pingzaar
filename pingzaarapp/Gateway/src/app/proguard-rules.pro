# EventBus
-keepattributes *Annotation*
-keepclassmembers class ** {
    @org.greenrobot.eventbus.Subscribe <methods>;
}
-keep enum org.greenrobot.eventbus.ThreadMode { *; }

# Gson
-keep class dev.titansys.smsgateway.models.Attachment { *; }
-keep class dev.titansys.smsgateway.models.MessageAttachment { *; }

# Retrofit & annotations
-keepattributes Signature, RuntimeVisibleAnnotations, RuntimeInvisibleAnnotations, EnclosingMethod, InnerClasses
-keepclassmembers class * {
    @retrofit2.http.* <methods>;
}
-keep interface retrofit2.** { *; }
-keep class retrofit2.** { *; }
-dontwarn retrofit2.**

# OkHttp
-dontwarn okhttp3.**
-keep class okhttp3.** { *; }

# Gson core
-keep class com.google.gson.** { *; }
-dontwarn com.google.gson.**

# Keep Gson type adapters and reflection metadata
-keepclassmembers class * {
    @com.google.gson.annotations.SerializedName <fields>;
}
-keepclassmembers class kotlin.Metadata { *; }

# Keep Kotlin coroutines continuation (needed for suspend calls)
-keep,allowobfuscation,allowshrinking class kotlin.coroutines.Continuation

# Fix for Retrofit + suspend + generic type erasure (the critical part)
-if interface * {
    @retrofit2.http.* public *** *(...);
}
-keep class <3>

# Keep your ApiResponse and models
-keep class dev.titansys.smsgateway.services.ApiResponse { *; }
-keep class dev.titansys.smsgateway.services.** { *; }

# Optional (avoid obfuscating model class fields)
-keepclassmembers class dev.titansys.smsgateway.services.** { *; }

# Advanced optimization rules for size reduction

# Remove debug logging in release builds
-assumenosideeffects class android.util.Log {
    public static boolean isLoggable(java.lang.String, int);
    public static int v(...);
    public static int i(...);
    public static int w(...);
    public static int d(...);
    public static int e(...);
}

# Remove System.out.println calls
-assumenosideeffects class java.lang.System {
    public static void out.println(...);
    public static void err.println(...);
}

# Basic optimization settings
-optimizationpasses 2
-allowaccessmodification

# Remove unused XML attributes
-keepattributes *Annotation*,Signature,Exception,InnerClasses,EnclosingMethod

# ========================================================================
# AndroidX Core - Critical for Android lifecycle and component creation
# ========================================================================

# CoreComponentFactory - Required by Android system for app initialization
-keep class androidx.core.app.CoreComponentFactory { *; }

# Keep all core app components used by Android framework
-keep class androidx.core.app.** { *; }
-keep class androidx.core.content.** { *; }

# FileProvider - Used in AndroidManifest.xml
-keep public class androidx.core.content.FileProvider { *; }

# Activity compatibility classes
-keep class androidx.core.app.ComponentActivity { *; }
-keep class androidx.core.app.ActivityCompat { *; }

# Notification compatibility (heavily used in app)
-keep class androidx.core.app.NotificationCompat { *; }
-keep class androidx.core.app.NotificationCompat$* { *; }

# Prevent obfuscation of core classes accessed via reflection
-keepclassmembers class androidx.core.** {
    public <methods>;
    public <fields>;
}

# Keep core library desugaring support
-dontwarn androidx.core.**

# ========================================================================
# Firebase Cloud Messaging - CRITICAL for FCM functionality
# ========================================================================

# Keep Firebase Messaging Service and core classes
-keep class com.google.firebase.messaging.FirebaseMessagingService { *; }
-keep class com.google.firebase.messaging.FirebaseMessaging { *; }
-keep class com.google.firebase.messaging.RemoteMessage { *; }
-keep class com.google.firebase.messaging.RemoteMessage$* { *; }

# Keep your FirebaseService implementation
-keep class dev.titansys.smsgateway.services.FirebaseService { *; }
-keep class * extends com.google.firebase.messaging.FirebaseMessagingService { *; }

# Keep Firebase internal classes
-keep class com.google.firebase.** { *; }
-keep class com.google.android.gms.** { *; }
-dontwarn com.google.firebase.**
-dontwarn com.google.android.gms.**

# Keep Firebase IID (Instance ID) for token management
-keep class com.google.firebase.iid.** { *; }
-keep class com.google.firebase.installations.** { *; }

# Prevent obfuscation of Firebase annotations
-keepattributes *Annotation*

# Keep Firebase JSON parsers and encoders
-keepclassmembers class * {
    @com.google.firebase.encoders.annotations.Encodable$Field <fields>;
}

# Keep Firebase data transport
-keep class com.google.android.datatransport.** { *; }
-dontwarn com.google.android.datatransport.**

# Keep Firebase components
-keep class com.google.firebase.components.** { *; }
-keep interface com.google.firebase.components.** { *; }

# Keep Firebase heartbeat
-keep class com.google.firebase.heartbeatinfo.** { *; }

# Keep WorkManager classes used by Firebase
-keep class androidx.work.impl.** { *; }
-keep class androidx.work.WorkManager { *; }
