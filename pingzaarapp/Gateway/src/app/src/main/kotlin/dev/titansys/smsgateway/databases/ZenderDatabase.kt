package dev.titansys.smsgateway.databases

import android.content.Context
import androidx.room.*

@Entity(tableName = "SmsMessages")
data class SmsMessage(
    @PrimaryKey
    val id: Int,
    val userId: Int,
    val campaignId: Int,
    val simSlot: Int,
    val recipientNumber: String,
    val messageContent: String,
    val priority: Int,
    val isProcessed: Boolean = false,

    // MMS support (Burat-inspired)
    val messageType: String = "SMS",      // "SMS" or "MMS"
    val attachment: String? = null        // Single attachment URL (Burat-style)
)

@Entity(tableName = "UssdRequests")
data class UssdRequest(
    @PrimaryKey
    val id: Int,
    val userId: Int,
    val simSlot: Int,
    val ussdCode: String,
    val priority: Int = 5, // Default priority: 5 (standard)
    val isProcessed: Boolean = false
)

@Entity(tableName = "Campaigns")
data class Campaign(
    @PrimaryKey(autoGenerate = true)
    val id: Int = 0,
    val campaignId: Int,
    val isActive: Boolean
)

@Entity(tableName = "ReceivedMessages")
data class ReceivedMessage(
    @PrimaryKey(autoGenerate = true)
    val id: Long = 0,
    val messageId: Long,
    val senderNumber: String,
    val messageContent: String,
    val simSlot: Int,
    val timestamp: Long,
    val receivedAt: Long,
    val isSynced: Boolean = false
)

@Dao
interface ZenderMessagesDao {
    @Query("SELECT * FROM SmsMessages WHERE isProcessed = 0")
    suspend fun getAllMessages(): List<SmsMessage>

    @Query("SELECT * FROM SmsMessages WHERE id = :id")
    suspend fun getMessageById(id: Int): SmsMessage?

    @Query("SELECT EXISTS(SELECT 1 FROM SmsMessages WHERE id = :id)")
    suspend fun messageExists(id: Int): Boolean

    @Query("SELECT * FROM SmsMessages WHERE userId = :userId")
    suspend fun getMessagesByUserId(userId: Int): List<SmsMessage>

    @Query("SELECT * FROM SmsMessages WHERE campaignId = :campaignId")
    suspend fun getMessagesByCampaignId(campaignId: Int): List<SmsMessage>

    @Query("DELETE FROM SmsMessages WHERE campaignId = :campaignId")
    suspend fun deleteMessagesByCampaignId(campaignId: Int): Int

    @Query("SELECT COUNT(*) FROM SmsMessages WHERE isProcessed = 0")
    suspend fun getMessageCount(): Int

    @Insert
    suspend fun insertMessage(message: SmsMessage)

    @Update
    suspend fun updateMessage(message: SmsMessage)

    @Delete
    suspend fun deleteMessage(message: SmsMessage)

    @Query("DELETE FROM SmsMessages WHERE id = :id")
    suspend fun deleteMessageById(id: Int)

    @Query("DELETE FROM SmsMessages")
    suspend fun deleteAll()
} 

@Dao
interface ZenderUssdDao {
    @Query("SELECT * FROM UssdRequests WHERE isProcessed = 0")
    suspend fun getAllUssd(): List<UssdRequest>

    @Query("SELECT * FROM UssdRequests WHERE id = :id")
    suspend fun getUssdById(id: Int): UssdRequest?

    @Query("SELECT EXISTS(SELECT 1 FROM UssdRequests WHERE id = :id)")
    suspend fun ussdExists(id: Int): Boolean

    @Query("SELECT * FROM UssdRequests WHERE userId = :userId")
    suspend fun getUssdByUserId(userId: Int): List<UssdRequest>

    @Insert
    suspend fun insertUssd(ussd: UssdRequest)

    @Update
    suspend fun updateUssd(ussd: UssdRequest)

    @Delete
    suspend fun deleteUssd(ussd: UssdRequest)

    @Query("DELETE FROM UssdRequests WHERE id = :id")
    suspend fun deleteUssdById(id: Int)

    @Query("DELETE FROM UssdRequests")
    suspend fun deleteAll()
} 

@Dao
interface CampaignDao {
    @Query("SELECT * FROM Campaigns WHERE campaignId = :campaignId")
    suspend fun getCampaign(campaignId: Int): Campaign?

    @Insert
    suspend fun insertCampaign(campaign: Campaign)

    @Update
    suspend fun updateCampaign(campaign: Campaign)

    @Delete
    suspend fun deleteCampaign(campaign: Campaign)

    @Query("DELETE FROM Campaigns")
    suspend fun deleteAll()
}

@Dao
interface ReceivedSmsDao {
    @Query("SELECT * FROM ReceivedMessages WHERE isSynced = 0 ORDER BY receivedAt ASC")
    suspend fun getUnsyncedMessages(): List<ReceivedMessage>

    @Query("SELECT * FROM ReceivedMessages WHERE id = :id")
    suspend fun getMessageById(id: Long): ReceivedMessage?

    @Query("SELECT * FROM ReceivedMessages ORDER BY receivedAt DESC LIMIT :limit")
    suspend fun getRecentMessages(limit: Int = 100): List<ReceivedMessage>

    @Query("SELECT COUNT(*) FROM ReceivedMessages WHERE isSynced = 0")
    suspend fun getUnsyncedCount(): Int

    @Insert
    suspend fun insertMessage(message: ReceivedMessage): Long

    @Update
    suspend fun updateMessage(message: ReceivedMessage)

    @Query("UPDATE ReceivedMessages SET isSynced = 1 WHERE id = :id")
    suspend fun markAsSynced(id: Long)

    @Query("UPDATE ReceivedMessages SET isSynced = 1 WHERE id IN (:ids)")
    suspend fun markMultipleAsSynced(ids: List<Long>)

    @Delete
    suspend fun deleteMessage(message: ReceivedMessage)

    @Query("DELETE FROM ReceivedMessages WHERE receivedAt < :timestamp")
    suspend fun deleteOldMessages(timestamp: Long)

    @Query("DELETE FROM ReceivedMessages")
    suspend fun deleteAll()
}

@Database(
    entities = [
        SmsMessage::class,
        UssdRequest::class,
        Campaign::class,
        ReceivedMessage::class
    ],
    version = 1
)
abstract class ZenderDatabase : RoomDatabase() {
    abstract fun messagesDao(): ZenderMessagesDao
    abstract fun ussdDao(): ZenderUssdDao
    abstract fun campaignDao(): CampaignDao
    abstract fun receivedSmsDao(): ReceivedSmsDao

    companion object {
        private var instance: ZenderDatabase? = null

        fun getInstance(context: Context): ZenderDatabase {
            return instance ?: synchronized(this) {
                instance ?: buildDatabase(context).also { instance = it }
            }
        }

        private fun buildDatabase(context: Context): ZenderDatabase {
            return Room.databaseBuilder(
                context.applicationContext,
                ZenderDatabase::class.java,
                "zender.db"
            )
            .build()
        }
    }
} 